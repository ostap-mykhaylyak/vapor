<?php
/**
 * Configurazione globale di Vapor.
 * Adatta i valori all'ambiente di deploy (host Linux con Incus/LXD).
 *
 * Tutti i parametri sono sovrascrivibili via variabili d'ambiente: l'elenco
 * completo e le procedure (installazione, certificati, avvio, deploy) sono in
 * HOWTO.md. Panoramica e modello di sicurezza in README.md.
 */
return [
    // --- Connessione a Incus ---
    'incus' => [
        // Socket Unix locale (deploy sullo stesso host di Incus).
        // In alternativa usare 'https' => 'https://host:8443' con certificato client.
        'socket'      => getenv('INCUS_SOCKET') ?: '/var/lib/incus/unix.socket',
        // Endpoint HTTPS opzionale (se non si usa il socket Unix).
        'https'       => getenv('INCUS_HTTPS') ?: null,
        // Certificato e chiave client per l'endpoint HTTPS.
        'client_cert' => getenv('INCUS_CLIENT_CERT') ?: null,
        'client_key'  => getenv('INCUS_CLIENT_KEY') ?: null,
        // Verifica TLS del server (false solo in dev con cert self-signed).
        'verify'      => filter_var(getenv('INCUS_VERIFY') ?: 'false', FILTER_VALIDATE_BOOL),
        // Project Incus di default.
        'project'     => getenv('INCUS_PROJECT') ?: 'default',
    ],

    // --- Autenticazione (login dashboard) ---
    'auth' => [
        // File SQLite con gli utenti. Va in una directory scrivibile.
        'db'           => getenv('VAPOR_DB') ?: __DIR__ . '/../storage/vapor.sqlite',
        'session_name' => 'vapor_session',
        // Durata massima della sessione in secondi (default 8 ore).
        'session_ttl'  => (int)(getenv('VAPOR_SESSION_TTL') ?: 28800),
    ],

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
