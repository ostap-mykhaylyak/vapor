<?php
namespace Vapor\Audit;

/**
 * Registro di audit: traccia azioni sensibili (login, operazioni sui
 * container, apertura terminale, gestione utenti) su SQLite.
 *
 * Condivide il file DB con Vapor\Auth\Auth.
 */
class Audit
{
    private \PDO $db;

    public function __construct(array $authConfig)
    {
        $this->db = $this->connect($authConfig['db']);
    }

    private function connect(string $path): \PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $db = new \PDO('sqlite:' . $path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS audit_log (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                at       INTEGER NOT NULL,
                username TEXT NOT NULL,
                action   TEXT NOT NULL,
                target   TEXT NOT NULL DEFAULT '',
                detail   TEXT NOT NULL DEFAULT '',
                ip       TEXT NOT NULL DEFAULT '',
                outcome  TEXT NOT NULL DEFAULT 'ok'
            )
        SQL);
        $db->exec('CREATE INDEX IF NOT EXISTS idx_audit_at ON audit_log(at)');
        return $db;
    }

    /**
     * Registra un evento. Non deve mai far fallire l'azione principale:
     * gli errori vengono ignorati.
     */
    public function log(
        string $username,
        string $action,
        string $target = '',
        string $detail = '',
        string $ip = '',
        string $outcome = 'ok'
    ): void {
        try {
            $this->db->prepare(
                'INSERT INTO audit_log (at, username, action, target, detail, ip, outcome)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([time(), $username, $action, $target, mb_substr($detail, 0, 500), $ip, $outcome]);
        } catch (\Throwable) {
            // best-effort: l'audit non deve bloccare l'operazione.
        }
    }

    /**
     * Ultimi eventi (per la UI admin), con filtri opzionali.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 200, ?string $username = null, ?string $action = null): array
    {
        $sql    = 'SELECT at, username, action, target, detail, ip, outcome FROM audit_log';
        $where  = [];
        $params = [];
        if ($username !== null && $username !== '') {
            $where[]  = 'username = ?';
            $params[] = $username;
        }
        if ($action !== null && $action !== '') {
            $where[]  = 'action = ?';
            $params[] = $action;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit));

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
