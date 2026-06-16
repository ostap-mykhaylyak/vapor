<?php
namespace Vapor\Incus;

/**
 * Client HTTP per l'API REST di Incus.
 *
 * Comunica via socket Unix (default) oppure via endpoint HTTPS con
 * certificato client. Gestisce l'envelope standard di Incus
 * (sync/async/error) e l'attesa delle operations asincrone.
 *
 * Riferimento API: https://linuxcontainers.org/incus/docs/main/rest-api/
 */
class IncusClient
{
    private string $project;

    public function __construct(private array $cfg)
    {
        $this->project = $cfg['project'] ?? 'default';
    }

    /* ---------------------------------------------------------------------
     *  Richieste HTTP di basso livello
     * ------------------------------------------------------------------- */

    /**
     * Esegue una richiesta cURL grezza e restituisce [statusCode, bodyString].
     *
     * @return array{0:int,1:string}
     */
    public function raw(string $method, string $path, ?array $body = null, array $query = []): array
    {
        // Aggiunge il project di default se non già presente.
        if (!isset($query['project']) && !str_contains($path, 'project=')) {
            $query['project'] = $this->project;
        }
        $url = $path . (empty($query) ? '' : '?' . http_build_query($query));

        $ch = curl_init();
        $headers = ['Accept: application/json'];

        if (!empty($this->cfg['https'])) {
            // Endpoint HTTPS con certificato client.
            curl_setopt($ch, CURLOPT_URL, rtrim($this->cfg['https'], '/') . $url);
            if (!empty($this->cfg['client_cert'])) {
                curl_setopt($ch, CURLOPT_SSLCERT, $this->cfg['client_cert']);
            }
            if (!empty($this->cfg['client_key'])) {
                curl_setopt($ch, CURLOPT_SSLKEY, $this->cfg['client_key']);
            }
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool)($this->cfg['verify'] ?? false));
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($this->cfg['verify'] ?? false) ? 2 : 0);
        } else {
            // Socket Unix locale. L'host nell'URL è arbitrario.
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->cfg['socket']);
            curl_setopt($ch, CURLOPT_URL, 'http://incus' . $url);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new IncusException('Errore di connessione a Incus (' . $this->target() . "): $err");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string)$response];
    }

    /** Descrizione leggibile dell'endpoint, per i messaggi d'errore. */
    private function target(): string
    {
        return !empty($this->cfg['https'])
            ? 'HTTPS ' . $this->cfg['https']
            : 'socket Unix ' . ($this->cfg['socket'] ?? '?');
    }

    /**
     * Come raw() ma restituisce anche gli header della risposta.
     * Usato dal file manager per leggere X-Incus-type, mode, uid/gid.
     * Permette inoltre di inviare header custom (es. upload file).
     *
     * @return array{0:int,1:array<string,string>,2:string}
     */
    public function rawWithHeaders(string $method, string $path, ?string $rawBody = null, array $extraHeaders = [], array $query = []): array
    {
        if (!isset($query['project']) && !str_contains($path, 'project=')) {
            $query['project'] = $this->project;
        }
        $url = $path . (empty($query) ? '' : '?' . http_build_query($query));

        $ch = curl_init();
        if (!empty($this->cfg['https'])) {
            curl_setopt($ch, CURLOPT_URL, rtrim($this->cfg['https'], '/') . $url);
            if (!empty($this->cfg['client_cert'])) curl_setopt($ch, CURLOPT_SSLCERT, $this->cfg['client_cert']);
            if (!empty($this->cfg['client_key']))  curl_setopt($ch, CURLOPT_SSLKEY, $this->cfg['client_key']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool)($this->cfg['verify'] ?? false));
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($this->cfg['verify'] ?? false) ? 2 : 0);
        } else {
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->cfg['socket']);
            curl_setopt($ch, CURLOPT_URL, 'http://incus' . $url);
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $respHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $header) use (&$respHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        });

        if ($rawBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }
        if (!empty($extraHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new IncusException('Errore di connessione a Incus (' . $this->target() . "): $err");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $respHeaders, (string)$body];
    }

    /**
     * Esegue una GET leggendo SOLO gli header e abortendo il download del
     * corpo. Serve a conoscere il tipo (X-Incus-type) di una entry del file
     * manager senza scaricare l'intero file.
     *
     * @return array{0:int,1:array<string,string>}
     */
    public function headersOnly(string $path, array $query = []): array
    {
        if (!isset($query['project']) && !str_contains($path, 'project=')) {
            $query['project'] = $this->project;
        }
        $url = $path . (empty($query) ? '' : '?' . http_build_query($query));

        $ch = curl_init();
        if (!empty($this->cfg['https'])) {
            curl_setopt($ch, CURLOPT_URL, rtrim($this->cfg['https'], '/') . $url);
            if (!empty($this->cfg['client_cert'])) curl_setopt($ch, CURLOPT_SSLCERT, $this->cfg['client_cert']);
            if (!empty($this->cfg['client_key']))  curl_setopt($ch, CURLOPT_SSLKEY, $this->cfg['client_key']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool)($this->cfg['verify'] ?? false));
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($this->cfg['verify'] ?? false) ? 2 : 0);
        } else {
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->cfg['socket']);
            curl_setopt($ch, CURLOPT_URL, 'http://incus' . $url);
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $respHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $header) use (&$respHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        });
        // Ritornando 0 dal write callback libcurl aborta il trasferimento del
        // corpo subito dopo gli header (CURLE_WRITE_ERROR, atteso).
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, fn($c, $data) => 0);

        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        curl_close($ch);

        if ($status === 0 && $errno !== 0 && $errno !== CURLE_WRITE_ERROR) {
            throw new IncusException('Errore di connessione a Incus (' . $this->target() . ')');
        }
        return [$status, $respHeaders];
    }

    /**
     * Come headersOnly() ma per molte query in parallelo (curl_multi), per
     * fare lo stat di tutte le voci di una directory senza N round-trip
     * sequenziali. Le query differiscono solo per i parametri (es. 'path').
     *
     * @param array<int,array<string,string>> $queries
     * @return array<int,array{status:int,headers:array<string,string>}> allineato a $queries
     */
    public function headersOnlyMulti(string $path, array $queries): array
    {
        $results = [];
        // Limita la concorrenza per non scommergere il server Incus.
        foreach (array_chunk($queries, 40, true) as $chunk) {
            $mh      = curl_multi_init();
            $handles = [];
            $headers = [];

            foreach ($chunk as $i => $query) {
                if (!isset($query['project']) && !str_contains($path, 'project=')) {
                    $query['project'] = $this->project;
                }
                $url = $path . (empty($query) ? '' : '?' . http_build_query($query));

                $ch = curl_init();
                if (!empty($this->cfg['https'])) {
                    curl_setopt($ch, CURLOPT_URL, rtrim($this->cfg['https'], '/') . $url);
                    if (!empty($this->cfg['client_cert'])) curl_setopt($ch, CURLOPT_SSLCERT, $this->cfg['client_cert']);
                    if (!empty($this->cfg['client_key']))  curl_setopt($ch, CURLOPT_SSLKEY, $this->cfg['client_key']);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool)($this->cfg['verify'] ?? false));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ($this->cfg['verify'] ?? false) ? 2 : 0);
                } else {
                    curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $this->cfg['socket']);
                    curl_setopt($ch, CURLOPT_URL, 'http://incus' . $url);
                }
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                $headers[$i] = [];
                curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $header) use (&$headers, $i) {
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $headers[$i][strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($header);
                });
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, fn($c, $data) => 0);

                $handles[$i] = $ch;
                curl_multi_add_handle($mh, $ch);
            }

            do {
                $mrc = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $mrc === CURLM_OK);

            foreach ($handles as $i => $ch) {
                $results[$i] = [
                    'status'  => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                    'headers' => $headers[$i] ?? [],
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        ksort($results);
        return $results;
    }

    /**
     * Esegue una richiesta e decodifica l'envelope Incus.
     * Per le operations async attende il completamento se $wait è true.
     *
     * @return array  metadata della risposta (o dell'operation completata)
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], bool $wait = true): array
    {
        [$status, $raw] = $this->raw($method, $path, $body, $query);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new IncusException("Risposta Incus non valida (HTTP $status): $raw", $status);
        }

        $type = $data['type'] ?? null;

        if ($type === 'error' || $status >= 400) {
            $msg = $data['error'] ?? $raw;
            throw new IncusException("Incus: $msg", (int)($data['error_code'] ?? $status));
        }

        if ($type === 'async') {
            $operation = $data['metadata'] ?? [];
            if ($wait && isset($operation['id'])) {
                return $this->waitOperation($operation['id']);
            }
            return $operation;
        }

        // type === 'sync'
        return $data['metadata'] ?? [];
    }

    /**
     * Attende il completamento di una operation asincrona.
     */
    public function waitOperation(string $id, int $timeout = 60): array
    {
        [$status, $raw] = $this->raw('GET', "/1.0/operations/$id/wait", null, ['timeout' => $timeout]);
        $data = json_decode($raw, true);
        $op   = $data['metadata'] ?? [];

        if (($op['status_code'] ?? 0) >= 400 || ($op['status'] ?? '') === 'Failure') {
            throw new IncusException('Operation fallita: ' . ($op['err'] ?? 'errore sconosciuto'), $status);
        }
        return $op;
    }

    /* ---------------------------------------------------------------------
     *  Helper HTTP
     * ------------------------------------------------------------------- */

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, null, $query);
    }

    public function post(string $path, ?array $body = null, bool $wait = true): array
    {
        return $this->request('POST', $path, $body, [], $wait);
    }

    public function put(string $path, array $body): array
    {
        return $this->request('PUT', $path, $body);
    }

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    public function project(): string
    {
        return $this->project;
    }

    public function config(): array
    {
        return $this->cfg;
    }
}
