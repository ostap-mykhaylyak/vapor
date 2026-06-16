# Vapor — Guida operativa (HOWTO)

Installazione, configurazione, certificati, avvio, deploy e comandi CLI.
Per la panoramica e il modello di sicurezza vedi **[README.md](README.md)**.

## Indice

1. [Requisiti](#requisiti)
2. [Variabili d'ambiente](#variabili-dambiente)
3. [Prima installazione (passo-passo)](#prima-installazione-passo-passo)
4. [Primo utente](#primo-utente)
5. [Connessione a Incus](#connessione-a-incus)
6. [Avvio](#avvio)
7. [Deploy (systemd)](#deploy-systemd)
8. [Riferimento CLI](#riferimento-cli)

---

## Requisiti

- PHP ≥ 8.2 con estensioni `curl`, `json`, `sockets`, `openssl`, `pdo_sqlite`.
- Nessun Composer, nessuna libreria esterna.
- Un host **Linux** con Incus in esecuzione (accesso al socket Unix oppure endpoint HTTPS).

Su Debian/Ubuntu: `sudo apt install php-cli php-curl php-sqlite3`.

## Variabili d'ambiente

Tutta la configurazione passa per variabili d'ambiente (default in `config/config.php`).

**Sorgente unica: il file `.env`.** Copia `.env.example` in `.env` alla radice del
progetto: viene letto **sia dalla CLI/web** (automaticamente) **sia dai servizi
systemd** (via `EnvironmentFile=/opt/vapor/.env`). Così non devi più esportare le
variabili a mano nella shell prima di lanciare `bin/vapor`.

```sh
cp .env.example .env
nano .env            # imposta almeno VAPOR_SECRET e i parametri del WebSocket
```

> I server Incus/LXD **non** stanno nel `.env`: si aggiungono dal web (vedi
> *Connessione a Incus*).

**Precedenza:** il `.env` ha **priorità massima** e **sovrascrive** qualunque
variabile d'ambiente già impostata (systemd, shell, inline). Ciò che non è nel
`.env` resta com'è. È quindi la sorgente di verità: metti lì tutto.

> Il file `.env` è escluso da git (contiene segreti). Versiona solo `.env.example`.

| Variabile            | Default                              | Note |
|----------------------|--------------------------------------|------|
| `VAPOR_STORAGE`      | `storage/`                           | Dir scrivibile: DB + certificati dei server |
| `VAPOR_WS_HOST`      | `127.0.0.1`                          | Bind del server WebSocket |
| `VAPOR_WS_PORT`      | `8090`                               | Porta del server WebSocket |
| `VAPOR_WS_URL`       | `ws://127.0.0.1:8090`                | URL pubblico usato dal browser |
| `VAPOR_WS_ORIGINS`   | (vuoto)                              | Origin consentite (anti CSWSH), es. `https://vapor.example.com` |
| `VAPOR_DB`           | `storage/vapor.sqlite`               | File SQLite (utenti, audit, token) |
| `VAPOR_SECRET`       | **da cambiare**                      | Firma dei token del terminale |
| `VAPOR_SESSION_TTL`  | `28800`                              | Durata sessione (secondi) |
| `VAPOR_DEBUG`        | `true`                               | **Metti `false` in produzione** |

## Prima installazione (passo-passo)

Installazione pulita, da zero (nessuna configurazione Incus nel `.env`).

1. **Copia il codice** sull'host (es. `/opt/vapor`) — nessuna dipendenza da installare.
2. **Configura il `.env`**: `cp .env.example .env`, imposta `VAPOR_SECRET` (lungo e
   casuale), `VAPOR_WS_URL`/`VAPOR_WS_ORIGINS` con l'host reale, `VAPOR_DEBUG=false`.
3. **Permessi storage**: l'utente di PHP (es. `www-data`) deve poter scrivere
   `storage/` (DB) e `storage/certs/` (certificati dei server):
   `sudo chown -R www-data:www-data /opt/vapor/storage`.
4. **Crea il primo amministratore**:
   `php bin/vapor user:add admin --admin` (vedi [Primo utente](#primo-utente)).
5. **Avvia** web server e terminal server (vedi [Avvio](#avvio) / [Deploy](#deploy-systemd)).
6. **Accedi** alla dashboard con l'utente del punto 4. Al primo accesso vieni
   reindirizzato a **Server**: aggiungi il tuo primo server Incus/LXD con un trust
   token (vedi [Connessione a Incus](#connessione-a-incus)).
7. Da lì gestisci container, file, forward e terminale sul server selezionato. Puoi
   aggiungere altri server in qualsiasi momento e passare dall'uno all'altro col
   selettore nella barra.

## Primo utente

L'accesso richiede un utente. Creane uno con la CLI:

```sh
php bin/vapor user:add admin --admin     # primo amministratore (password interattiva)
php bin/vapor user:add mario             # utente normale
php bin/vapor user:role mario admin      # promuovi/declassa
php bin/vapor user:list
php bin/vapor user:del mario
```

Gli admin possono poi gestire gli utenti dall'interfaccia (**Utenti** nella navbar).
Il database SQLite viene creato automaticamente (`storage/vapor.sqlite`).

## Connessione a Incus (server gestiti dal web)

I server Incus/LXD si aggiungono **dall'interfaccia**, come amministratore, dal menu
**Server** (`/admin/servers`). Vapor supporta **più server**: ognuno ha un endpoint
HTTPS, un project e una coppia di certificati client generata automaticamente.
La selezione del server attivo avviene dal **menu a tendina nella barra in alto**.

### Aggiungere un server

1. Sull'host Incus, da amministratore, genera un **trust token** monouso:
   ```sh
   incus config trust add <nome>      # stampa un trust token temporaneo
   ```
   (Assicurati che l'API HTTPS sia raggiungibile: `incus config set core.https_address :8443`.)
2. In Vapor → **Server** → *Aggiungi server*: inserisci nome, URL
   (`https://host:8443`), project e incolla il trust token.
3. Vapor genera il certificato client (EC secp384r1, fallback RSA-4096) in
   `storage/certs/<nome>/`, lo **registra** sul server tramite il token e salva il
   server nel DB. Diventa subito selezionabile.

> Spunta "Verifica certificato TLS" solo se il certificato del server è verificabile
> dal CA di sistema; per i tipici certificati self-signed di Incus lascialo disattivo.

### Note

- I dati dei server (URL, project, percorsi certificati) vivono nel **DB**, non nel
  `.env`. Aggiungere/rimuovere server non richiede riavvii.
- L'utente che esegue PHP deve poter **leggere e scrivere** `storage/certs/`
  (è dove vengono salvati i certificati dei server).
- Il primo server aggiunto diventa il **default**; puoi cambiarlo dalla lista.

> I comandi `cert:*` della CLI restano disponibili per usi manuali, ma per l'uso
> normale la gestione dei server è interamente via web.

## Avvio

Due processi:

```sh
# 1) Web server (dev)
php -S 127.0.0.1:8080 -t public

# 2) Server WebSocket del terminale (tenere sempre attivo)
php bin/terminal-server.php
```

Apri `http://127.0.0.1:8080` e accedi.

## Deploy (systemd)

In `deploy/` trovi le unit pronte:

```sh
sudo cp -r . /opt/vapor
sudo useradd -r -s /usr/sbin/nologin -G incus-admin vapor   # accesso al socket Incus
sudo cp deploy/vapor-terminal.service /etc/systemd/system/
sudo cp deploy/vapor-web.service /etc/systemd/system/        # oppure nginx+php-fpm
sudo systemctl daemon-reload
sudo systemctl enable --now vapor-terminal vapor-web
```

> **Importante:** `VAPOR_SECRET` deve essere **identico** tra web server e
> terminal server (firma dei token del terminale). Per produzione usa
> `deploy/nginx.conf.example` (nginx + php-fpm + proxy WebSocket TLS) e disabilita
> `vapor-web.service`.

L'utente che esegue PHP deve poter leggere il socket di Incus (gruppo `incus-admin`),
oppure usare la modalità HTTPS con certificato.

## Riferimento CLI

`php bin/vapor <comando>`:

| Comando | Descrizione |
|---------|-------------|
| `user:add <username> [password] [--admin]` | Crea/aggiorna un utente (password interattiva se omessa) |
| `user:role <username> <admin\|user>` | Cambia il ruolo |
| `user:del <username>` | Elimina un utente |
| `user:list` | Elenca gli utenti |
| `cert:generate [--cn=vapor] [--days=3650]` | Genera certificato + chiave client in `storage/certs/` |
| `cert:install [--name=vapor] [--token=<trust-token>]` | Registra il certificato nel trust store di Incus |
| `cert:setup [--cn=…] [--days=…] [--name=…] [--token=…]` | `cert:generate` + `cert:install` insieme |
