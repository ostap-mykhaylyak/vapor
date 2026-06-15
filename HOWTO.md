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
nano .env            # imposta almeno VAPOR_SECRET e, per HTTPS, INCUS_HTTPS + cert/key
```

**Precedenza:** il `.env` ha **priorità massima** e **sovrascrive** qualunque
variabile d'ambiente già impostata (systemd, shell, inline). Ciò che non è nel
`.env` resta com'è. È quindi la sorgente di verità: metti lì tutto.

> Il file `.env` è escluso da git (contiene segreti). Versiona solo `.env.example`.

| Variabile            | Default                              | Note |
|----------------------|--------------------------------------|------|
| `INCUS_SOCKET`       | `/var/lib/incus/unix.socket`         | Socket Unix locale |
| `INCUS_PROJECT`      | `default`                            | Project Incus |
| `INCUS_HTTPS`        | (vuoto)                              | Endpoint HTTPS; se valorizzato ignora il socket |
| `INCUS_CLIENT_CERT`  | `storage/certs/client.crt`           | Certificato client (modalità HTTPS) |
| `INCUS_CLIENT_KEY`   | `storage/certs/client.key`           | Chiave client (modalità HTTPS) |
| `INCUS_VERIFY`       | `false`                              | Verifica TLS del server |
| `VAPOR_WS_HOST`      | `127.0.0.1`                          | Bind del server WebSocket |
| `VAPOR_WS_PORT`      | `8090`                               | Porta del server WebSocket |
| `VAPOR_WS_URL`       | `ws://127.0.0.1:8090`                | URL pubblico usato dal browser |
| `VAPOR_WS_ORIGINS`   | (vuoto)                              | Origin consentite (anti CSWSH), es. `https://vapor.example.com` |
| `VAPOR_DB`           | `storage/vapor.sqlite`               | File SQLite (utenti, audit, token) |
| `VAPOR_SECRET`       | **da cambiare**                      | Firma dei token del terminale |
| `VAPOR_SESSION_TTL`  | `28800`                              | Durata sessione (secondi) |
| `VAPOR_DEBUG`        | `true`                               | **Metti `false` in produzione** |

## Prima installazione (passo-passo)

1. **Copia il codice** sull'host (es. `/opt/vapor`) — nessuna dipendenza da installare.
2. **Crea il primo amministratore**:
   `php bin/vapor user:add admin --admin` (vedi [Primo utente](#primo-utente)).
3. **Collega Incus** scegliendo una modalità (vedi [Connessione a Incus](#connessione-a-incus)):
   - **A)** socket Unix locale — Vapor sullo stesso host di Incus, nessun certificato;
   - **B1)** HTTPS *fornendo il certificato* — `php bin/vapor cert:setup` (host con
     accesso admin a Incus);
   - **B2)** HTTPS *con trust token* — l'admin Incus esegue `incus config trust add vapor`
     e tu lanci `php bin/vapor cert:setup --token=<trust-token>` (host remoto).
4. **Imposta i segreti**: `VAPOR_SECRET` lungo e casuale (**identico** tra web e
   terminal server), `VAPOR_WS_ORIGINS` con l'host reale, `VAPOR_DEBUG=false`.
5. **Avvia** web server e terminal server (vedi [Avvio](#avvio) / [Deploy](#deploy-systemd)).
6. **Apri la dashboard** e accedi con l'utente creato al punto 2.

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

## Connessione a Incus

Vapor può parlare con Incus in due modi: scegline uno.

### Opzione A — Socket Unix locale (default)

Se Vapor gira **sullo stesso host** di Incus non serve alcun certificato: è
sufficiente che l'utente di PHP possa leggere `/var/lib/incus/unix.socket`
(appartenenza al gruppo `incus-admin`). Nessuna variabile aggiuntiva.

### Opzione B — API HTTPS con certificato client

Per collegarsi a Incus **via rete** serve un certificato client TLS registrato nel
trust store di Incus. Vapor genera la coppia cert/chiave (EC secp384r1, fallback
RSA-4096) in `storage/certs/`. La fiducia si stabilisce in uno di questi due modi.

**B1 — Fornendo il certificato** (host con accesso admin a Incus, es. il socket locale)

```sh
php bin/vapor cert:setup            # genera la coppia E la registra nel trust store
# equivalente ai due passi separati:
php bin/vapor cert:generate --cn=vapor --days=3650
php bin/vapor cert:install  --name=vapor
```

**B2 — Con trust token** (registrazione remota, senza accesso al socket)

Sull'host di Incus, un amministratore genera un token monouso:

```sh
incus config trust add vapor        # stampa un trust token temporaneo
```

Su Vapor (host remoto): genera il certificato, **poi imposta l'endpoint HTTPS nel
`.env`** (la registrazione col token avviene via HTTPS, non sul socket) e registrati.

```sh
php bin/vapor cert:generate --cn=vapor
```

Nel `.env` (così la CLI lo legge subito, senza export manuali):

```sh
INCUS_HTTPS=https://mio-host:8443
INCUS_CLIENT_CERT=/opt/vapor/storage/certs/client.crt
INCUS_CLIENT_KEY=/opt/vapor/storage/certs/client.key
INCUS_VERIFY=false
```

Poi registra il certificato col token:

```sh
php bin/vapor cert:install --name=vapor --token=<trust-token>
```

> Se INCUS_HTTPS non è impostato, `cert:install --token` si ferma con un messaggio
> esplicito (la registrazione con token richiede l'endpoint HTTPS).

**Configurazione finale (B1 o B2)** — i percorsi sono quelli stampati dai comandi:

```sh
INCUS_HTTPS=https://mio-host:8443
INCUS_CLIENT_CERT=/opt/vapor/storage/certs/client.crt
INCUS_CLIENT_KEY=/opt/vapor/storage/certs/client.key
INCUS_VERIFY=false        # true se il certificato del server è verificabile
```

Quando `INCUS_HTTPS` è valorizzato, il socket Unix viene ignorato (sia per le API
REST sia per il WebSocket del terminale). La chiave privata è scritta con permessi
`0600`; `storage/` è già escluso da git.

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
