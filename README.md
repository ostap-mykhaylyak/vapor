# Vapor

Dashboard **PHP MVC** per **Incus / LXD**. Niente Node.js e **nessuna dipendenza
esterna** (solo PHP e le sue estensioni): il backend, incluso il terminale PTY, è
interamente in PHP. Comunica con Incus via **API REST** (socket Unix o HTTPS); il
terminale usa un **server WebSocket scritto a mano** (handshake + framing RFC 6455)
che fa da proxy verso l'endpoint `exec` di Incus.

> 📖 Per installazione, configurazione, certificati, avvio, deploy e comandi CLI vedi
> **[HOWTO.md](HOWTO.md)**.

## Funzionalità

- **Istanze** — CRUD completo + start/stop/restart/freeze/unfreeze.
- **Network forward** — gestione port forwarding lato rete (`/1.0/networks/{net}/forwards`).
- **File manager** — browse, lettura/scrittura, upload, mkdir, delete dentro l'istanza.
- **Terminale PTY** — shell interattiva via xterm.js, proxy WS → `exec` di Incus.
- **Multi-server** — più server Incus/LXD aggiunti via web dall'admin (cert generato
  e registrato con trust token); selettore del server attivo nella barra.
- **Autenticazione e ruoli** — login su SQLite + sessioni; utenti `admin`/`user`.
- **Multi-tenancy** — ogni utente vede e gestisce solo i propri container.
- **Audit log** — tracciamento delle azioni sensibili.

## Architettura

```
Browser ──HTTP──> public/index.php (front controller MVC)
   │                  └─ Controller → Vapor\Incus\*Service → IncusClient ──(socket Unix / HTTPS)──> Incus API
   │
   └──WebSocket──> bin/terminal-server.php (PHP puro) ──(socket Unix / TLS)──> Incus exec WS
```

- `app/autoload.php` — autoloader PSR-4 autonomo (niente Composer richiesto).
- `app/Core` — router, request/response, view, kernel, token firmati, CSRF, security headers.
- `app/Incus` — client REST e service di dominio (Instance, NetworkForward, File, Exec, Cert).
- `app/Auth`, `app/Audit` — autenticazione (SQLite) e registro di audit.
- `app/Controller` — controller HTTP.
- `app/Terminal` — server WebSocket PHP puro per il PTY (WebSocket, TerminalServer, Conn, Session).
- `app/views` — template PHP. `public/` — webroot + asset (xterm.js vendored).
- `bin/` — eseguibili CLI. `config/` — configurazione. `deploy/` — unit systemd + nginx.

## Requisiti

- PHP ≥ 8.2 con estensioni `curl`, `json`, `sockets`, `openssl`, `pdo_sqlite`.
- **Nessun Composer, nessuna libreria esterna.**
- Un host **Linux** con Incus installato e in esecuzione.

> Lo sviluppo può avvenire su qualsiasi OS, ma il runtime va eseguito su un host con
> accesso a Incus (socket Unix locale, oppure endpoint HTTPS).

---

# Modello di sicurezza

## Multi-tenancy (isolamento per utente)

Ogni container è marcato col proprietario tramite la chiave di config Incus
`user.vapor-owner`. La dashboard mostra e consente di operare **solo sui propri
container**; gli **admin** vedono e gestiscono tutto.

- Alla creazione, il container viene assegnato all'utente che lo crea.
- Ogni operazione (dettaglio, start/stop, file manager, terminale) verifica la
  proprietà; per i non proprietari il container risulta *inesistente* (404, per
  non rivelarne l'esistenza).
- I non-admin non possono modificare le chiavi `security.*` né riassegnare la
  proprietà.
- I **network forward** e la **gestione utenti** sono riservati agli admin.

## Sicurezza del terminale (anti furto di sessione)

Il terminale è il punto più sensibile (shell nel container). Difesa in profondità:

1. **Solo il proprietario** (o un admin) può ottenere un token.
2. Token **firmato HS256** con username, ruolo, istanza e `jti`.
3. **Monouso**: il `jti` è registrato su DB e consumato atomicamente dal server
   WS — un token intercettato non è riutilizzabile (anti-replay).
4. **TTL breve** (60s).
5. Il server WS **ri-verifica la proprietà** del container contro Incus alla
   connessione.
6. **Controllo Origin** del WebSocket (anti Cross-Site WebSocket Hijacking) —
   imposta `VAPOR_WS_ORIGINS` in produzione.

## Hardening generale

- **CSRF** su tutte le richieste POST/PUT/DELETE (token di sessione sincrono).
- **Header di sicurezza**: CSP con *nonce* (niente inline script arbitrari),
  `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`,
  `Permissions-Policy`, COOP.
- **Cookie di sessione** `HttpOnly`, `SameSite=Strict`, `Secure` su HTTPS;
  rigenerazione dell'id al login e rotazione periodica; scadenza per inattività.
- **Throttling del login**: lockout dopo 5 tentativi falliti in 15 minuti per IP;
  verifica a tempo costante anche per utenti inesistenti (anti user-enumeration).
- **Path traversal** neutralizzato nel file manager.
- **Nessuna CDN**: xterm.js è *vendored* in `public/assets/vendor/xterm/` (MIT),
  così la CSP per gli script è `'self' 'nonce-…'` senza origin esterne.

> ⚠️ Chi accede a Vapor ottiene shell e gestione dei propri container: cambia
> `VAPOR_SECRET`, usa HTTPS e tieni la dashboard dietro rete fidata/reverse proxy.

## Audit log

Gli eventi sensibili sono tracciati su SQLite (tabella `audit_log`) e consultabili
dagli admin in **Audit** (navbar), con filtri per utente e azione. Vengono registrati:

- `auth.login` (ok/fail), `auth.logout`;
- `instance.create` / `.start` / `.stop` / `.restart` / `.freeze` / `.unfreeze` / `.update` / `.delete`;
- `file.write` / `.mkdir` / `.upload` / `.delete`;
- `terminal.token` (emissione) e **`terminal.connect`** (connessione reale, loggata
  dal server WS con l'IP del peer);
- `user.create` / `.role` / `.password` / `.delete`.

Ogni voce salva timestamp, utente, azione, target, dettaglio, IP ed esito.
