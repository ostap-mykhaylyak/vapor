<?php
namespace Vapor\Terminal;

use Vapor\Core\Token;
use Vapor\Auth\Auth;
use Vapor\Audit\Audit;
use Vapor\Incus\IncusClient;
use Vapor\Incus\ExecService;
use Vapor\Incus\InstanceService;
use Vapor\Incus\ServerRepository;

/**
 * Server WebSocket per il terminale PTY, in PHP puro (ext-sockets via stream).
 *
 * Un singolo loop stream_select() multiplexa:
 *   - il socket di ascolto TCP (connessioni dal browser);
 *   - per ogni sessione, i due WebSocket verso Incus (dati + controllo),
 *     aperti su socket Unix.
 *
 * Per ogni browser: handshake WS, validazione token, creazione operation
 * exec su Incus, apertura dei due WS verso l'operation e bridging dei byte.
 */
class TerminalServer
{
    /** @var array<int,Conn> connessioni attive indicizzate per id stream */
    private array $conns = [];

    /** @var resource */
    private $listen;

    public function __construct(private array $config)
    {
    }

    public function run(): void
    {
        $host = $this->config['ws']['host'];
        $port = $this->config['ws']['port'];

        $this->listen = @stream_socket_server("tcp://$host:$port", $errno, $errstr);
        if (!$this->listen) {
            fwrite(STDERR, "[Vapor] Impossibile aprire la porta $host:$port — $errstr\n");
            exit(1);
        }
        stream_set_blocking($this->listen, false);
        fwrite(STDOUT, "[Vapor] Terminal server in ascolto su ws://$host:$port\n");

        while (true) {
            $read   = [$this->listen];
            $write  = [];
            foreach ($this->conns as $c) {
                $read[] = $c->stream;
                if ($c->wbuf !== '') {
                    $write[] = $c->stream;
                }
            }
            $except = null;

            if (@stream_select($read, $write, $except, null) === false) {
                continue; // interrotto da segnale
            }

            foreach ($read as $stream) {
                if ($stream === $this->listen) {
                    $this->acceptBrowser();
                } else {
                    $this->onReadable($stream);
                }
            }
            foreach ($write as $stream) {
                $conn = $this->conns[(int)$stream] ?? null;
                if ($conn) {
                    $this->flush($conn);
                }
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Accettazione e lettura                                             */
    /* ------------------------------------------------------------------ */

    private function acceptBrowser(): void
    {
        $client = @stream_socket_accept($this->listen, 0);
        if (!$client) {
            return;
        }
        stream_set_blocking($client, false);
        $conn = new Conn($client, 'browser');
        $this->conns[$conn->id()] = $conn;
    }

    private function onReadable(mixed $stream): void
    {
        $conn = $this->conns[(int)$stream] ?? null;
        if (!$conn) {
            return;
        }

        $data = @fread($stream, 65535);
        if ($data === '' || $data === false) {
            if (feof($stream)) {
                $this->closeConn($conn);
            }
            return;
        }
        $conn->rbuf .= $data;

        if (!$conn->handshaked) {
            $this->tryBrowserHandshake($conn);
            return;
        }

        $frames = WebSocket::decode($conn->rbuf);
        foreach ($frames as $f) {
            $this->dispatchFrame($conn, $f);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Handshake browser + avvio sessione                                 */
    /* ------------------------------------------------------------------ */

    private function tryBrowserHandshake(Conn $conn): void
    {
        $pos = strpos($conn->rbuf, "\r\n\r\n");
        if ($pos === false) {
            return; // header non ancora completo
        }
        $header     = substr($conn->rbuf, 0, $pos);
        $conn->rbuf = substr($conn->rbuf, $pos + 4); // eventuali frame successivi

        // Riga di richiesta: "GET /path?query HTTP/1.1"
        $requestLine = strtok($header, "\r\n");
        $query       = [];
        if (preg_match('#GET\s+(\S+)\s+HTTP#', $requestLine, $m)) {
            $qs = parse_url($m[1], PHP_URL_QUERY) ?: '';
            parse_str($qs, $query);
        }

        if (!preg_match('#Sec-WebSocket-Key:\s*(.+)#i', $header, $km)) {
            $this->closeConn($conn);
            return;
        }

        // Controllo Origin (anti Cross-Site WebSocket Hijacking). Se configurato
        // un elenco di origin consentite, l'header Origin deve combaciare.
        if (!$this->originAllowed($header)) {
            // Rifiuto a livello HTTP, prima dell'upgrade.
            $this->write($conn, "HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n");
            $this->closeConn($conn);
            return;
        }

        $this->write($conn, WebSocket::serverHandshake(trim($km[1])));
        $conn->handshaked = true;

        // 1) Verifica firma + scadenza del token.
        $claims = Token::verify($this->config['app']['secret'], $query['token'] ?? '');
        if ($claims === null || empty($claims['jti']) || empty($claims['inst']) || empty($claims['sub'])) {
            $this->reject($conn, 'Token non valido o scaduto.');
            return;
        }

        // 2) Consumo monouso del token (anti-replay / furto). Atomico su DB.
        try {
            $record = (new Auth($this->config['auth']))->consumeTerminalToken($claims['jti']);
        } catch (\Throwable $e) {
            $this->reject($conn, 'Errore di validazione del token.');
            return;
        }
        if ($record === null
            || $record['username'] !== $claims['sub']
            || $record['instance'] !== $claims['inst']) {
            $this->reject($conn, 'Token già usato o non valido.');
            return;
        }

        $instance = $claims['inst'];

        // 2b) Carica la config del server Incus indicato nel token (multi-server).
        $incusCfg = $this->serverConfig((int)($claims['srv'] ?? 0));
        if ($incusCfg === null) {
            $this->reject($conn, 'Server Incus non disponibile.');
            return;
        }

        // 3) Ri-verifica della proprietà contro Incus (difesa in profondità).
        if (($claims['role'] ?? 'user') !== 'admin' && !$this->ownsInstance($claims['sub'], $instance, $incusCfg)) {
            $this->reject($conn, 'Accesso al container non autorizzato.');
            return;
        }

        // Audit: connessione terminale effettivamente stabilita.
        try {
            $peer = @stream_socket_get_name($conn->stream, true) ?: '';
            (new Audit($this->config['auth']))->log(
                $claims['sub'], 'terminal.connect', $instance, 'jti=' . $claims['jti'], $peer
            );
        } catch (\Throwable) {}

        $session       = new Session($conn, $instance, $incusCfg);
        $conn->session = $session;

        $this->openIncusSession($session, (int)($query['cols'] ?? 80), (int)($query['rows'] ?? 24));
    }

    /** Config IncusClient del server indicato, o null se assente. */
    private function serverConfig(int $serverId): ?array
    {
        try {
            $row = (new ServerRepository($this->config['auth']))->find($serverId);
        } catch (\Throwable) {
            return null;
        }
        return $row ? ServerRepository::toClientConfig($row) : null;
    }

    /** Verifica che l'header Origin sia tra quelli consentiti (se configurati). */
    private function originAllowed(string $header): bool
    {
        $allowed = $this->config['ws']['allowed_origins'] ?? [];
        if (empty($allowed)) {
            return true; // nessuna restrizione configurata
        }
        if (!preg_match('#^Origin:\s*(.+)$#im', $header, $m)) {
            return false; // origin obbligatorio se la whitelist è attiva
        }
        return in_array(trim($m[1]), $allowed, true);
    }

    /** True se l'istanza Incus è marcata come di proprietà dell'utente. */
    private function ownsInstance(string $username, string $instance, array $incusCfg): bool
    {
        try {
            $incus = new IncusClient($incusCfg);
            $owner = (new InstanceService($incus))->ownerOf($instance);
        } catch (\Throwable $e) {
            return false;
        }
        return $owner === $username;
    }

    /** Invia un messaggio di errore al browser e chiude. */
    private function reject(Conn $conn, string $message): void
    {
        $this->sendText($conn, "\r\n\x1b[31m[Vapor] $message\x1b[0m\r\n");
        $this->closeConn($conn);
    }

    /**
     * Crea l'operation exec su Incus e apre i due WebSocket di dati/controllo.
     */
    private function openIncusSession(Session $session, int $cols, int $rows): void
    {
        try {
            $incus = new IncusClient($session->incusCfg);
            $op    = (new ExecService($incus))->start($session->instance, [
                'width'  => $cols,
                'height' => $rows,
            ]);
        } catch (\Throwable $e) {
            $this->sendText($session->browser, "\r\n\x1b[31m[Vapor] Avvio exec fallito: {$e->getMessage()}\x1b[0m\r\n");
            $this->closeConn($session->browser);
            return;
        }

        try {
            $session->data = $this->openIncusWs($op['id'], $op['fds']['0'] ?? '', 'data', $session);
            if (!empty($op['fds']['control'])) {
                $session->control = $this->openIncusWs($op['id'], $op['fds']['control'], 'control', $session);
            }
        } catch (\Throwable $e) {
            $this->sendText($session->browser, "\r\n\x1b[31m[Vapor] Connessione PTY fallita: {$e->getMessage()}\x1b[0m\r\n");
            $this->closeSession($session);
        }
    }

    /**
     * Apre un WebSocket verso l'operation Incus (socket Unix o HTTPS) e
     * completa l'handshake in modo bloccante.
     */
    private function openIncusWs(string $opId, string $secret, string $role, Session $session): Conn
    {
        $cfg      = $session->incusCfg;
        $useHttps = !empty($cfg['https']);
        $path     = "/1.0/operations/$opId/websocket?secret=$secret";

        if ($useHttps) {
            $base = preg_replace('#^https?://#', '', rtrim($cfg['https'], '/'));
            $ctx  = stream_context_create(['ssl' => [
                'verify_peer'      => (bool)($cfg['verify'] ?? false),
                'verify_peer_name' => (bool)($cfg['verify'] ?? false),
                'local_cert'       => $cfg['client_cert'] ?? null,
                'local_pk'         => $cfg['client_key'] ?? null,
            ]]);
            $stream = @stream_socket_client("ssl://$base", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
            $hostHeader = parse_url($cfg['https'], PHP_URL_HOST) ?: 'incus';
        } else {
            $sock   = $cfg['socket'];
            $stream = @stream_socket_client("unix://$sock", $errno, $errstr, 5);
            $hostHeader = 'incus';
        }

        if (!$stream) {
            throw new \RuntimeException("connessione WS Incus fallita: $errstr");
        }

        [$req] = WebSocket::clientHandshake($path, $hostHeader);
        fwrite($stream, $req);

        // Legge la risposta di handshake (bloccante con timeout).
        stream_set_timeout($stream, 5);
        $resp = '';
        while (!str_contains($resp, "\r\n\r\n")) {
            $chunk = fread($stream, 1024);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $resp .= $chunk;
        }
        if (!str_contains($resp, ' 101 ')) {
            fclose($stream);
            throw new \RuntimeException('handshake WS Incus rifiutato');
        }

        stream_set_blocking($stream, false);
        $conn          = new Conn($stream, $role);
        $conn->handshaked = true;
        $conn->session = $session;
        // Eventuali byte di frame già ricevuti dopo l'header.
        $hpos = strpos($resp, "\r\n\r\n");
        $conn->rbuf = $hpos !== false ? substr($resp, $hpos + 4) : '';
        $this->conns[$conn->id()] = $conn;

        // Processa subito eventuali frame iniziali.
        if ($conn->rbuf !== '') {
            foreach (WebSocket::decode($conn->rbuf) as $f) {
                $this->dispatchFrame($conn, $f);
            }
        }
        return $conn;
    }

    /* ------------------------------------------------------------------ */
    /*  Instradamento dei frame                                            */
    /* ------------------------------------------------------------------ */

    private function dispatchFrame(Conn $conn, array $frame): void
    {
        $opcode  = $frame['opcode'];
        $payload = $frame['payload'];

        if ($opcode === WebSocket::OP_CLOSE) {
            $conn->session ? $this->closeSession($conn->session) : $this->closeConn($conn);
            return;
        }
        if ($opcode === WebSocket::OP_PING) {
            $this->write($conn, WebSocket::encode($payload, WebSocket::OP_PONG, $conn->role !== 'browser'));
            return;
        }
        if ($opcode === WebSocket::OP_PONG) {
            return;
        }

        $session = $conn->session;
        if (!$session) {
            return;
        }

        if ($conn->role === 'browser') {
            // Messaggio di controllo (resize) in JSON.
            if ($payload !== '' && $payload[0] === '{') {
                $msg = json_decode($payload, true);
                if (is_array($msg) && ($msg['type'] ?? '') === 'resize') {
                    $this->sendResize($session, (int)($msg['cols'] ?? 80), (int)($msg['rows'] ?? 24));
                    return;
                }
            }
            // Altrimenti input da tastiera -> canale dati (frame mascherato).
            if ($session->data) {
                $this->write($session->data, WebSocket::encode($payload, WebSocket::OP_BIN, true));
            }
        } else {
            // Output del PTY (canale dati) -> browser (frame non mascherato).
            if ($conn->role === 'data') {
                $this->write($session->browser, WebSocket::encode($payload, WebSocket::OP_BIN, false));
            }
            // Il canale di controllo in entrata viene ignorato.
        }
    }

    private function sendResize(Session $session, int $cols, int $rows): void
    {
        if (!$session->control) {
            return;
        }
        $payload = json_encode([
            'command' => 'window-resize',
            'args'    => ['width' => (string)$cols, 'height' => (string)$rows],
        ], JSON_UNESCAPED_SLASHES);
        $this->write($session->control, WebSocket::encode($payload, WebSocket::OP_TEXT, true));
    }

    /* ------------------------------------------------------------------ */
    /*  I/O e chiusura                                                     */
    /* ------------------------------------------------------------------ */

    /** Invia testo grezzo (handshake) oppure accoda a wbuf. */
    private function write(Conn $conn, string $data): void
    {
        $conn->wbuf .= $data;
        $this->flush($conn);
    }

    /** Manda un frame di testo verso il browser (messaggi diagnostici). */
    private function sendText(Conn $conn, string $text): void
    {
        $this->write($conn, WebSocket::encode($text, WebSocket::OP_BIN, false));
    }

    /** Svuota il buffer di scrittura per quanto possibile (non bloccante). */
    private function flush(Conn $conn): void
    {
        if ($conn->wbuf === '') {
            return;
        }
        $written = @fwrite($conn->stream, $conn->wbuf);
        if ($written === false) {
            $this->closeConn($conn);
            return;
        }
        $conn->wbuf = substr($conn->wbuf, $written);
    }

    /**
     * Chiude una connessione. Se appartiene a una sessione, abbatte l'intera
     * sessione (browser + canali Incus): la chiusura di un qualsiasi lato
     * termina il terminale.
     */
    private function closeConn(Conn $conn): void
    {
        if ($conn->session) {
            $this->closeSession($conn->session);
            return;
        }
        $this->dropStream($conn);
    }

    /** Chiude l'intera sessione (browser + dati + controllo). */
    private function closeSession(Session $session): void
    {
        // Avvisa il browser, best-effort, se ancora aperto.
        if (is_resource($session->browser->stream)) {
            @fwrite($session->browser->stream,
                WebSocket::encode("\r\n\x1b[33m[Vapor] Sessione terminata.\x1b[0m\r\n", WebSocket::OP_BIN));
        }
        foreach ([$session->data, $session->control, $session->browser] as $c) {
            if ($c) {
                $this->dropStream($c);
            }
        }
        $session->data = $session->control = null;
    }

    /** Rimuove uno stream dal loop e lo chiude. */
    private function dropStream(Conn $conn): void
    {
        unset($this->conns[$conn->id()]);
        if (is_resource($conn->stream)) {
            @fclose($conn->stream);
        }
    }
}
