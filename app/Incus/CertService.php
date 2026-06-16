<?php
namespace Vapor\Incus;

/**
 * Generazione del certificato client per l'autenticazione TLS verso Incus
 * e sua installazione nel trust store di Incus.
 *
 * Il certificato è uno X.509 self-signed (EC secp384r1, come i default di
 * Incus; fallback RSA-4096). Incus autentica i client tramite il loro
 * certificato registrato: non serve una CA.
 */
class CertService
{
    public function __construct(private IncusClient $incus) {}

    /**
     * Genera coppia certificato/chiave e la scrive su file.
     *
     * @return array{cert:string,key:string,fingerprint:string}
     */
    public function generate(string $cn, int $days, string $certPath, string $keyPath): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException("L'estensione PHP openssl non è disponibile.");
        }

        $pkey = $this->newKey();

        $dn  = ['commonName' => $cn !== '' ? $cn : 'vapor'];
        $cfg = ['digest_alg' => 'sha384'];

        $csr  = openssl_csr_new($dn, $pkey, $cfg);
        if ($csr === false) {
            throw new \RuntimeException('Generazione CSR fallita: ' . $this->opensslErrors());
        }
        $x509 = openssl_csr_sign($csr, null, $pkey, $days, $cfg, random_int(1, PHP_INT_MAX));
        if ($x509 === false) {
            throw new \RuntimeException('Firma certificato fallita: ' . $this->opensslErrors());
        }

        if (!openssl_x509_export($x509, $certPem) || !openssl_pkey_export($pkey, $keyPem)) {
            throw new \RuntimeException('Export certificato/chiave fallito: ' . $this->opensslErrors());
        }

        $this->writeFile($certPath, $certPem, 0644);
        $this->writeFile($keyPath, $keyPem, 0600);

        return [
            'cert'        => $certPath,
            'key'         => $keyPath,
            'fingerprint' => openssl_x509_fingerprint($certPem, 'sha256') ?: '',
        ];
    }

    /**
     * Registra il certificato nel trust store di Incus.
     *
     * Due modalità:
     *  - admin/locale (socket Unix): senza token, richiede privilegi admin;
     *  - remota con trust token: il client si auto-registra presentando il
     *    proprio certificato in TLS e includendo il token monouso ottenuto
     *    dall'admin con `incus config trust add <name>`.
     *
     * @return array{name:string,fingerprint:string}
     */
    public function install(string $certPath, string $name, ?string $token = null): array
    {
        if (!is_file($certPath)) {
            throw new \RuntimeException("Certificato non trovato: $certPath — esegui prima 'cert:generate'.");
        }

        // Validazione del canale di registrazione in base alla modalità.
        $cfg     = $this->incus->config();
        $useHttps = !empty($cfg['https']);

        if ($token !== null && $token !== '' && !$useHttps) {
            throw new \RuntimeException(
                "La registrazione con trust token avviene via HTTPS: imposta INCUS_HTTPS "
                . "(e INCUS_CLIENT_CERT/INCUS_CLIENT_KEY) prima di 'cert:install --token'."
            );
        }
        if (!$useHttps && !@file_exists($cfg['socket'] ?? '')) {
            throw new \RuntimeException(
                "Socket Incus non trovato: " . ($cfg['socket'] ?? '?') . ". "
                . "Verifica che Incus sia in esecuzione e il percorso (INCUS_SOCKET); "
                . "se Vapor è su un host remoto usa la modalità HTTPS con trust token."
            );
        }

        $pem = (string)file_get_contents($certPath);

        // Il campo `certificate` dell'API è il corpo base64 (DER) del PEM,
        // cioè il PEM senza intestazioni e senza spazi/newline.
        $body = preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '';
        if ($body === '') {
            throw new \RuntimeException('Certificato PEM non valido.');
        }

        $payload = [
            'type'        => 'client',
            'name'        => $name,
            'certificate' => $body,
        ];
        if ($token !== null && $token !== '') {
            // Auto-registrazione remota: il token autorizza l'aggiunta.
            $payload['trust_token'] = $token;
        }

        $this->incus->post('/1.0/certificates', $payload);

        return [
            'name'        => $name,
            'fingerprint' => openssl_x509_fingerprint($pem, 'sha256') ?: '',
        ];
    }

    /**
     * Recupera il certificato TLS presentato dal server (PEM), per il
     * "certificate pinning": permette la verifica TLS anche con cert
     * self-signed di Incus, senza affidarsi alle CA di sistema.
     */
    public static function fetchServerCert(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?: 8443;
        if (!$host) {
            return null;
        }
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
        ]]);
        $client = @stream_socket_client(
            "ssl://$host:$port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$client) {
            return null;
        }
        $params = stream_context_get_params($client);
        $cert   = $params['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);

        if (!$cert || !openssl_x509_export($cert, $pem)) {
            return null;
        }
        return $pem;
    }

    /** True se un certificato con quella fingerprint è già nel trust store. */
    public function isInstalled(string $fingerprint): bool
    {
        try {
            $list = $this->incus->get('/1.0/certificates');
        } catch (\Throwable) {
            return false;
        }
        foreach ($list as $entry) {
            // recursion=0 restituisce URL tipo /1.0/certificates/<fingerprint>
            if (is_string($entry) && str_ends_with($entry, '/' . $fingerprint)) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------ */

    private function newKey(): \OpenSSLAsymmetricKey
    {
        // EC secp384r1 (default di Incus); fallback RSA-4096.
        $ec = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'secp384r1',
        ]);
        if ($ec instanceof \OpenSSLAsymmetricKey) {
            return $ec;
        }
        $rsa = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 4096,
        ]);
        if (!$rsa instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Generazione chiave fallita: ' . $this->opensslErrors());
        }
        return $rsa;
    }

    private function writeFile(string $path, string $content, int $mode): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossibile creare la directory: $dir");
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Scrittura fallita: $path");
        }
        @chmod($path, $mode);
    }

    private function opensslErrors(): string
    {
        $errs = [];
        while ($e = openssl_error_string()) {
            $errs[] = $e;
        }
        return implode('; ', $errs) ?: 'errore sconosciuto';
    }
}
