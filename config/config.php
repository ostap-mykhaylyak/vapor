<?php
/**
 * Configurazione globale di Vapor.
 * Adatta i valori all'ambiente di deploy (host Linux con Incus/LXD).
 *
 * Tutti i parametri sono sovrascrivibili via variabili d'ambiente: l'elenco
 * completo e le procedure (installazione, certificati, avvio, deploy) sono in
 * HOWTO.md. Panoramica e modello di sicurezza in README.md.
 *
 * Sorgente unica con priorità massima: il file ".env" alla radice del progetto
 * (vedi .env.example). Sovrascrive qualunque variabile d'ambiente già impostata
 * (systemd, shell); ciò che non è nel .env resta invariato.
 */
\Vapor\Core\Env::load(__DIR__ . '/../.env');

return [
    // --- Connessione a Incus ---
    // I server Incus/LXD sono gestiti dagli amministratori via web e salvati nel
    // DB (tabella `servers`). Questo blocco è solo il fallback "vuoto" usato
    // quando nessun server è ancora selezionato.
    'incus' => [
        'socket'      => null,
        'https'       => null,
        'client_cert' => null,
        'client_key'  => null,
        'verify'      => false,
        'project'     => 'default',
    ],

    // --- Autenticazione (login dashboard) ---
    'auth' => [
        // File SQLite con gli utenti. Va in una directory scrivibile.
        'db'           => getenv('VAPOR_DB') ?: __DIR__ . '/../storage/vapor.sqlite',
        'session_name' => 'vapor_session',
        // Durata massima della sessione in secondi (default 8 ore).
        'session_ttl'  => (int)(getenv('VAPOR_SESSION_TTL') ?: 28800),
    ],

    // Directory scrivibile per dati a runtime (DB, certificati dei server).
    'storage' => getenv('VAPOR_STORAGE') ?: __DIR__ . '/../storage',

    // --- Server WebSocket per il terminale PTY ---
    'ws' => [
        'host' => getenv('VAPOR_WS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('VAPOR_WS_PORT') ?: 8090),
        // URL pubblico usato dal browser (xterm.js) per collegarsi al proxy.
        'public_url' => getenv('VAPOR_WS_URL') ?: 'ws://127.0.0.1:8090',
        // Origin consentite per il WebSocket (anti CSWSH). Vuoto = nessuna
        // restrizione (solo per sviluppo). In produzione imposta l'origin reale.
        'allowed_origins' => array_filter(array_map('trim', explode(',', getenv('VAPOR_WS_ORIGINS') ?: ''))),
    ],

    // --- App ---
    'app' => [
        'name'     => 'Vapor',
        'base_url' => getenv('VAPOR_BASE_URL') ?: '',
        'debug'    => filter_var(getenv('VAPOR_DEBUG') ?: 'true', FILTER_VALIDATE_BOOL),
        // Segreto condiviso tra front controller e server WS per firmare i
        // token effimeri del terminale. CAMBIALO in produzione.
        'secret'   => getenv('VAPOR_SECRET') ?: 'cambiami-in-produzione-please',
    ],
];
