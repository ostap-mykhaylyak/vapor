<?php
namespace Vapor\Incus;

/**
 * Operazioni CRUD e di stato sulle istanze (container/VM) Incus.
 */
class InstanceService
{
    /** Chiave config Incus che marca il proprietario di un'istanza. */
    public const OWNER_KEY = 'user.vapor-owner';

    public function __construct(private IncusClient $incus) {}

    /**
     * Elenco istanze con dettaglio completo (recursion=2).
     */
    public function all(): array
    {
        return $this->incus->get('/1.0/instances', ['recursion' => 2]);
    }

    /**
     * Elenco filtrato per proprietario (multi-tenancy).
     * Gli admin vedono tutto.
     */
    public function ownedBy(string $username, bool $isAdmin = false): array
    {
        $all = $this->all();
        if ($isAdmin) {
            return $all;
        }
        return array_values(array_filter($all, function ($i) use ($username) {
            return ($i['config'][self::OWNER_KEY] ?? null) === $username;
        }));
    }

    /** Proprietario marcato sull'istanza (o null se non assegnato). */
    public function ownerOf(string $name): ?string
    {
        $instance = $this->find($name);
        return $instance['config'][self::OWNER_KEY] ?? null;
    }

    /**
     * Dettaglio di una singola istanza.
     */
    public function find(string $name): array
    {
        return $this->incus->get('/1.0/instances/' . rawurlencode($name));
    }

    /**
     * Stato runtime (rete, processi, memoria...).
     */
    public function state(string $name): array
    {
        return $this->incus->get('/1.0/instances/' . rawurlencode($name) . '/state');
    }

    /**
     * Crea una nuova istanza.
     *
     * @param array{name:string,image:string,server?:string,protocol?:string,type?:string,profiles?:array} $opts
     */
    public function create(array $opts): array
    {
        $config = $opts['config'] ?? [];
        // Marca il proprietario per il modello multi-tenant.
        if (!empty($opts['owner'])) {
            $config[self::OWNER_KEY] = $opts['owner'];
        }

        $body = [
            'name'   => $opts['name'],
            'type'   => $opts['type'] ?? 'container',
            'source' => [
                'type'     => 'image',
                'alias'    => $opts['image'],
                'protocol' => $opts['protocol'] ?? 'simplestreams',
                'server'   => $opts['server'] ?? 'https://images.linuxcontainers.org',
            ],
            'config' => $config,
        ];
        if (!empty($opts['profiles'])) {
            $body['profiles'] = $opts['profiles'];
        }
        return $this->incus->post('/1.0/instances', $body);
    }

    /**
     * Aggiorna la configurazione (merge) di un'istanza.
     */
    public function update(string $name, array $config): array
    {
        return $this->incus->patch('/1.0/instances/' . rawurlencode($name), $config);
    }

    /**
     * Rinomina un'istanza.
     */
    public function rename(string $name, string $newName): array
    {
        return $this->incus->post('/1.0/instances/' . rawurlencode($name), ['name' => $newName]);
    }

    /**
     * Elimina un'istanza (deve essere stopped).
     */
    public function delete(string $name): array
    {
        return $this->incus->delete('/1.0/instances/' . rawurlencode($name));
    }

    /* --- Cambio di stato --- */

    public function start(string $name): array   { return $this->changeState($name, 'start'); }
    public function stop(string $name): array     { return $this->changeState($name, 'stop'); }
    public function restart(string $name): array  { return $this->changeState($name, 'restart'); }
    public function freeze(string $name): array   { return $this->changeState($name, 'freeze'); }
    public function unfreeze(string $name): array { return $this->changeState($name, 'unfreeze'); }

    private function changeState(string $name, string $action, bool $force = false, int $timeout = 30): array
    {
        return $this->incus->put('/1.0/instances/' . rawurlencode($name) . '/state', [
            'action'  => $action,
            'timeout' => $timeout,
            'force'   => $force,
        ]);
    }
}
