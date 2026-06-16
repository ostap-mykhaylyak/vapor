<?php
namespace Vapor\Core;

use Vapor\Incus\IncusClient;
use Vapor\Incus\IncusException;
use Vapor\Incus\ServerRepository;
use Vapor\Auth\Auth;

/**
 * Kernel applicativo: carica config, registra le rotte e fa il dispatch.
 */
class App
{
    private Router $router;
    private array $config;
    private IncusClient $incus;
    private Auth $auth;
    private ServerRepository $servers;

    /** Rotte accessibili senza autenticazione. */
    private array $publicPaths = ['/login'];

    public function __construct(array $config)
    {
        $this->config  = $config;
        $this->router  = new Router();
        $this->incus   = new IncusClient($config['incus']);
        $this->auth    = new Auth($config['auth']);
        $this->servers = new ServerRepository($config['auth']);
    }

    public function incus(): IncusClient { return $this->incus; }
    public function router(): Router     { return $this->router; }
    public function config(): array      { return $this->config; }

    /**
     * Esegue la richiesta corrente e invia la risposta.
     */
    public function run(Request $request): void
    {
        try {
            Security::sendHeaders();
            $this->auth->startSession();

            // Guard: rotte protette richiedono autenticazione.
            if (!$this->isPublic($request->path) && !$this->auth->check()) {
                if ($request->wantsJson()) {
                    Response::json(['error' => 'Non autenticato'], 401)->send();
                } else {
                    Response::redirect(($this->config['app']['base_url'] ?? '') . '/login')->send();
                }
                return;
            }

            // Guard CSRF per i metodi che modificano stato.
            if (in_array($request->method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                $token = $request->input('_csrf') ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
                if (!Csrf::validate($token)) {
                    $this->error($request, 419, 'Token CSRF mancante o non valido. Ricarica la pagina.')->send();
                    return;
                }
            }

            // Risoluzione del server Incus corrente (multi-server).
            if ($this->auth->check()) {
                $this->resolveCurrentServer($request);
                if ($this->needsServer($request->path) && empty($this->config['_current_server'])) {
                    $this->handleNoServer($request)->send();
                    return;
                }
            }

            $match = $this->router->match($request->method, $request->path);
            if ($match === null) {
                $this->error($request, 404, 'Pagina non trovata')->send();
                return;
            }
            [$handler, $params] = $match;
            $request   = $request->withParams($params);
            $response  = $this->invoke($handler, $request);
            $response->send();
        } catch (HttpException $e) {
            $this->error($request, $e->status, $e->getMessage())->send();
        } catch (IncusException $e) {
            $this->error($request, 502, 'Errore Incus: ' . $e->getMessage())->send();
        } catch (\Throwable $e) {
            $msg = ($this->config['app']['debug'] ?? false)
                ? $e->getMessage() . "\n" . $e->getTraceAsString()
                : 'Errore interno';
            $this->error($request, 500, $msg)->send();
        }
    }

    private function invoke(callable|array $handler, Request $request): Response
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class($this->config, $this->incus);
            return $controller->$method($request);
        }
        return $handler($request);
    }

    private function isPublic(string $path): bool
    {
        return in_array($path, $this->publicPaths, true);
    }

    /**
     * Determina il server Incus attivo (da sessione o default) e costruisce
     * il relativo IncusClient. Espone elenco e server corrente alle view.
     */
    private function resolveCurrentServer(Request $request): void
    {
        $current = null;
        $sid     = $_SESSION['vapor_server'] ?? null;
        if ($sid !== null) {
            $current = $this->servers->find((int)$sid);
        }
        if ($current === null) {
            $current = $this->servers->default();
        }

        if ($current !== null) {
            $_SESSION['vapor_server'] = (int)$current['id'];
            $this->incus = new IncusClient(ServerRepository::toClientConfig($current));
        }

        // Contesto esposto alla navbar (lista server + corrente).
        $this->config['_servers']        = $this->servers->all();
        $this->config['_current_server'] = $current;
    }

    /** Le rotte che operano su Incus richiedono un server configurato. */
    private function needsServer(string $path): bool
    {
        return $path === '/'
            || str_starts_with($path, '/instances')
            || str_starts_with($path, '/forwards');
    }

    /** Nessun server: admin → pagina server; utente → messaggio. */
    private function handleNoServer(Request $request): Response
    {
        $base = $this->config['app']['base_url'] ?? '';
        if ($this->auth->isAdmin()) {
            return Response::redirect($base . '/admin/servers?notice=Aggiungi+un+server+Incus+per+iniziare');
        }
        if ($request->wantsJson()) {
            return Response::json(['error' => 'Nessun server Incus configurato'], 503);
        }
        return Response::html(View::render('error', [
            'status'  => 503,
            'message' => 'Nessun server Incus configurato. Contatta un amministratore.',
            'config'  => $this->config,
        ]), 503);
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $message], $status);
        }
        return Response::html(
            View::render('error', ['status' => $status, 'message' => $message, 'config' => $this->config]),
            $status
        );
    }
}
