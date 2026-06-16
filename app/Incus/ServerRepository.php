<?php
namespace Vapor\Incus;

/**
 * Anagrafica dei server Incus/LXD gestiti via web dagli amministratori.
 *
 * Ogni server ha un endpoint HTTPS, un project e una coppia di certificati
 * client (file su disco) usati per l'autenticazione TLS. Condivide il file
 * SQLite con Auth/Audit.
 */
class ServerRepository
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
            CREATE TABLE IF NOT EXISTS servers (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL UNIQUE,
                url         TEXT NOT NULL,
                project     TEXT NOT NULL DEFAULT 'default',
                verify      INTEGER NOT NULL DEFAULT 0,
                cert_path   TEXT NOT NULL,
                key_path    TEXT NOT NULL,
                server_cert TEXT,
                is_default  INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);

        // Migrazione idempotente per DB preesistenti.
        $cols = $db->query('PRAGMA table_info(servers)')->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (!in_array('server_cert', $cols, true)) {
            $db->exec('ALTER TABLE servers ADD COLUMN server_cert TEXT');
        }
        return $db;
    }

    /** @return array<int,array<string,mixed>> elenco (senza percorsi cert) */
    public function all(): array
    {
        return $this->db->query(
            'SELECT id, name, url, project, verify, is_default, created_at
             FROM servers ORDER BY name'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM servers WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Server di default (o il primo disponibile, o null). */
    public function default(): ?array
    {
        $row = $this->db->query('SELECT * FROM servers WHERE is_default = 1 LIMIT 1')
            ->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $row = $this->db->query('SELECT * FROM servers ORDER BY id LIMIT 1')
            ->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function count(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM servers')->fetchColumn();
    }

    /**
     * @param array{name:string,url:string,project:string,verify:bool,cert_path:string,key_path:string,server_cert?:?string} $data
     */
    public function create(array $data): int
    {
        $first = $this->count() === 0;
        $stmt  = $this->db->prepare(
            'INSERT INTO servers (name, url, project, verify, cert_path, key_path, server_cert, is_default)
             VALUES (:name, :url, :project, :verify, :cert, :key, :scert, :def)'
        );
        $stmt->execute([
            ':name'    => $data['name'],
            ':url'     => $data['url'],
            ':project' => $data['project'] ?: 'default',
            ':verify'  => !empty($data['verify']) ? 1 : 0,
            ':cert'    => $data['cert_path'],
            ':key'     => $data['key_path'],
            ':scert'   => $data['server_cert'] ?? null,
            ':def'     => $first ? 1 : 0,   // il primo server diventa default
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM servers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function setDefault(int $id): void
    {
        $this->db->exec('UPDATE servers SET is_default = 0');
        $this->db->prepare('UPDATE servers SET is_default = 1 WHERE id = ?')->execute([$id]);
    }

    /**
     * Converte una riga server nella config attesa da IncusClient.
     */
    public static function toClientConfig(array $row): array
    {
        return [
            'https'       => $row['url'],
            'client_cert' => $row['cert_path'],
            'client_key'  => $row['key_path'],
            'verify'      => (bool)$row['verify'],
            'cafile'      => $row['server_cert'] ?? null,  // cert del server pinnato
            'project'     => $row['project'] ?? 'default',
            'socket'      => null,
        ];
    }
}
