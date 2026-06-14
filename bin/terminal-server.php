<?php
/**
 * Server WebSocket per il terminale PTY di Vapor — PHP puro, nessuna
 * dipendenza esterna.
 *
 * Avvio (su host Linux con Incus):
 *   php bin/terminal-server.php
 *
 * Espone ws://VAPOR_WS_HOST:VAPOR_WS_PORT/ verso il browser e fa da proxy
 * verso l'endpoint exec di Incus. Va tenuto in esecuzione come servizio
 * (systemd / supervisor) separato dal web server PHP.
 */
require __DIR__ . '/../app/autoload.php';

use Vapor\Terminal\TerminalServer;

$config = require __DIR__ . '/../config/config.php';

(new TerminalServer($config))->run();
