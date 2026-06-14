<?php
namespace Vapor\Auth;

/**
 * Autenticazione e autorizzazione basate su SQLite + sessioni PHP.
 *
 * Tabelle:
 *   users            — utenti (username, hash, role: admin|user)
 *   login_attempts   — tentativi di login per throttling/anti brute-force
 *   terminal_tokens  — token monouso del terminale (anti-replay/furto sessione)
 */
class Auth
{
    private \PDO $db;
    private array $cfg;

    private const MAX_ATTEMPTS = 5;     // tentativi falliti...
    private const WINDOW       = 900;   // ...entro questa finestra (secondi) => lock

    public function __construct(array $authConfig)
    {
        $this->cfg = $authConfig;
        $this->db  = $this->connect($authConfig['db']);
    }

    /* ------------------------------------------------------------------ */
    /*  Database / migrazioni                                              */
    /* ------------------------------------------------------------------ */

    private function connect(string $path): \PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $db = new \PDO('sqlite:' . $path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');

        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role          TEXT NOT NULL DEFAULT 'user',
                created_at    TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS login_attempts (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ip       TEXT NOT NULL,
                username TEXT NOT NULL,
                at       INTEGER NOT NULL
            )
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS terminal_tokens (
                jti        TEXT PRIMARY KEY,
                username   TEXT NOT NULL,
                instance   TEXT NOT NULL,
                expires_at INTEGER NOT NULL,
                used       INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL
            )
        SQL);

        // Migrazione idempotente: aggiunge 'role' a DB preesistenti.
        $cols = $db->query('PRAGMA table_info(users)')->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (!in_array('role', $cols, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
        }
        return $db;
    }

    /* ------------------------------------------------------------------ */
    /*  Sessione                                                           */
    /* ------------------------------------------------------------------ */

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name($this->cfg['session_name'] ?? 'vapor_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        ]);
        session_start();

        $ttl = $this->cfg['session_ttl'] ?? 28800;
        if (isset($_SESSION['vapor_last']) && (time() - $_SESSION['vapor_last']) > $ttl) {
            $this->logout();
        }
        // Rotazione periodica dell'id di sessione (mitiga fissazione/furto).
        if (!isset($_SESSION['vapor_rotated']) || (time() - $_SESSION['vapor_rotated']) > 1800) {
            if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION)) {
                session_regenerate_id(true);
            }
            $_SESSION['vapor_rotated'] = time();
        }
        $_SESSION['vapor_last'] = time();
    }

    public function check(): bool
    {
        return !empty($_SESSION['vapor_user']);
    }

    /** @return array{id:int,username:string,role:string}|null */
    public function user(): ?array
    {
        return $_SESSION['vapor_user'] ?? null;
    }

    public function isAdmin(): bool
    {
        return ($_SESSION['vapor_user']['role'] ?? '') === 'admin';
    }

    /* ------------------------------------------------------------------ */
    /*  Login / throttling                                                 */
    /* ------------------------------------------------------------------ */

    /** True se l'IP ha superato il numero di tentativi falliti nella finestra. */
    public function isLocked(string $ip): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?'
        );
        $stmt->execute([$ip, time() - self::WINDOW]);
        return (int)$stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function attempt(string $username, string $password, string $ip = ''): bool
    {
        $stmt = $this->db->prepare('SELECT id, username, role, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Verifica sempre un hash fittizio se l'utente non esiste, per non
        // rivelare la sua esistenza tramite timing.
        $hash = $row['password_hash'] ?? '$2y$12$nonexistentnonexistentnonexistentnonexistentnonexist';
        $ok   = password_verify($password, $hash) && $row !== false;

        if (!$ok) {
            $this->recordFailure($ip, $username);
            return false;
        }

        $this->clearFailures($ip);
        session_regenerate_id(true);
        $_SESSION['vapor_user'] = [
            'id'       => (int)$row['id'],
            'username' => $row['username'],
            'role'     => $row['role'] ?? 'user',
        ];
        $_SESSION['vapor_last']    = time();
        $_SESSION['vapor_rotated'] = time();
        return true;
    }

    private function recordFailure(string $ip, string $username): void
    {
        $this->db->prepare('INSERT INTO login_attempts (ip, username, at) VALUES (?, ?, ?)')
            ->execute([$ip, $username, time()]);
    }

    private function clearFailures(string $ip): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Gestione utenti                                                    */
    /* ------------------------------------------------------------------ */

    public function createUser(string $username, string $password, string $role = 'user'): void
    {
        $role = $role === 'admin' ? 'admin' : 'user';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->prepare(
            'INSERT INTO users (username, password_hash, role) VALUES (:u, :h, :r)
             ON CONFLICT(username) DO UPDATE SET password_hash = :h, role = :r'
        )->execute([':u' => $username, ':h' => $hash, ':r' => $role]);
    }

    public function setRole(string $username, string $role): bool
    {
        $role = $role === 'admin' ? 'admin' : 'user';
        $stmt = $this->db->prepare('UPDATE users SET role = ? WHERE username = ?');
        $stmt->execute([$role, $username]);
        return $stmt->rowCount() > 0;
    }

    public function setPassword(string $username, string $password): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $username]);
        return $stmt->rowCount() > 0;
    }

    public function deleteUser(string $username): bool
    {
        $stmt = $this->db->prepare('DELETE FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int,array{username:string,role:string,created_at:string}> */
    public function listUsers(): array
    {
        return $this->db->query('SELECT username, role, created_at FROM users ORDER BY username')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function count(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /* ------------------------------------------------------------------ */
    /*  Token monouso del terminale (anti-replay / furto sessione)         */
    /* ------------------------------------------------------------------ */

    /** Registra un token del terminale appena emesso. */
    public function storeTerminalToken(string $jti, string $username, string $instance, int $expiresAt): void
    {
        $this->db->prepare(
            'INSERT INTO terminal_tokens (jti, username, instance, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$jti, $username, $instance, $expiresAt, time()]);
    }

    /**
     * Consuma un token del terminale in modo atomico (single-use).
     * Restituisce username+instance se valido e non ancora usato, altrimenti null.
     *
     * @return array{username:string,instance:string}|null
     */
    public function consumeTerminalToken(string $jti): ?array
    {
        // Aggiornamento atomico: marca usato solo se non usato e non scaduto.
        $upd = $this->db->prepare(
            'UPDATE terminal_tokens SET used = 1
             WHERE jti = ? AND used = 0 AND expires_at >= ?'
        );
        $upd->execute([$jti, time()]);
        if ($upd->rowCount() !== 1) {
            return null;
        }
        $sel = $this->db->prepare('SELECT username, instance FROM terminal_tokens WHERE jti = ?');
        $sel->execute([$jti]);
        $row = $sel->fetch(\PDO::FETCH_ASSOC);

        // Pulizia opportunistica dei token vecchi.
        $this->db->prepare('DELETE FROM terminal_tokens WHERE expires_at < ?')->execute([time() - 60]);

        return $row ?: null;
    }
}
