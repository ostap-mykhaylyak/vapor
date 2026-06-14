<?php
namespace Vapor\Core;

use Vapor\Incus\IncusClient;
use Vapor\Incus\InstanceService;
use Vapor\Incus\NetworkForwardService;
use Vapor\Incus\FileService;
use Vapor\Incus\ExecService;
use Vapor\Audit\Audit;

/**
 * Controller base con accesso ai service e helper di risposta.
 */
abstract class Controller
{
    protected InstanceService $instances;
    protected NetworkForwardService $forwards;
    protected FileService $files;
    protected ExecService $exec;

    public function __construct(protected array $config, protected IncusClient $incus)
    {
        $this->instances = new InstanceService($incus);
        $this->forwards  = new NetworkForwardService($incus);
        $this->files     = new FileService($incus);
        $this->exec      = new ExecService($incus);
    }

    protected function view(string $template, array $data = [], string $layout = 'layout'): Response
    {
        $data += [
            'config'   => $this->config,
            'authUser' => $this->user(),
            'isAdmin'  => $this->isAdmin(),
        ];
        return Response::html(View::render($template, $data, $layout));
    }

    /* --- Utente corrente e autorizzazione --- */

    /** @return array{id:int,username:string,role:string}|null */
    protected function user(): ?array
    {
        return $_SESSION['vapor_user'] ?? null;
    }

    protected function username(): string
    {
        return $_SESSION['vapor_user']['username'] ?? '';
    }

    protected function isAdmin(): bool
    {
        return ($_SESSION['vapor_user']['role'] ?? '') === 'admin';
    }

    /** Registra un evento nel log di audit (best-effort). */
    protected function audit(string $action, string $target = '', string $detail = '', string $outcome = 'ok'): void
    {
        $this->auditAs($this->username() ?: 'anonimo', $action, $target, $detail, $outcome);
    }

    /** Come audit() ma con username esplicito (utile prima del login). */
    protected function auditAs(string $username, string $action, string $target = '', string $detail = '', string $outcome = 'ok'): void
    {
        (new Audit($this->config['auth']))->log(
            $username ?: 'anonimo',
            $action,
            $target,
            $detail,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $outcome
        );
    }

    /** Richiede ruolo admin, altrimenti 403. */
    protected function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new HttpException(403, 'Accesso riservato agli amministratori.');
        }
    }

    /**
     * Verifica che l'utente corrente possieda l'istanza (o sia admin).
     * Restituisce i dati dell'istanza. Lancia 403/404 altrimenti.
     */
    protected function assertInstanceOwner(string $name): array
    {
        try {
            $instance = $this->instances->find($name);
        } catch (\Throwable $e) {
            throw new HttpException(404, 'Istanza non trovata.');
        }
        if ($this->isAdmin()) {
            return $instance;
        }
        $owner = $instance['config'][InstanceService::OWNER_KEY] ?? null;
        if ($owner !== $this->username()) {
            // 404 (non 403) per non rivelare l'esistenza di container altrui.
            throw new HttpException(404, 'Istanza non trovata.');
        }
        return $instance;
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $to): Response
    {
        $base = $this->config['app']['base_url'] ?? '';
        return Response::redirect($base . $to);
    }

    protected function back(Request $request): Response
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? ($this->config['app']['base_url'] ?? '') . '/';
        return Response::redirect($ref);
    }
}
