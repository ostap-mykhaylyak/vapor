<?php
namespace Vapor\Controller;

use Vapor\Core\Controller;
use Vapor\Core\Request;
use Vapor\Core\Response;
use Vapor\Core\HttpException;
use Vapor\Incus\IncusClient;
use Vapor\Incus\ServerRepository;
use Vapor\Incus\CertService;

/**
 * Gestione dei server Incus/LXD (CRUD, solo admin) e selezione del server
 * attivo in sessione (qualsiasi utente autenticato).
 */
class ServerController extends Controller
{
    private function repo(): ServerRepository
    {
        return new ServerRepository($this->config['auth']);
    }

    /** Elenco server + form di aggiunta (admin). */
    public function index(Request $request): Response
    {
        $this->requireAdmin();
        $notice = $request->input('notice');
        if (is_string($notice)) {
            $notice = preg_replace('/[^\p{L}\p{N} ()+._:\/-]/u', '', $notice);
        }
        return $this->view('admin/servers', [
            'servers' => $this->repo()->all(),
            'notice'  => $notice,
        ]);
    }

    /** Aggiunge un server: genera il certificato e lo registra con il token. */
    public function store(Request $request): Response
    {
        $this->requireAdmin();
        $repo = $this->repo();

        $name    = trim((string)$request->input('name', ''));
        $url     = trim((string)$request->input('url', ''));
        $project = trim((string)$request->input('project', 'default')) ?: 'default';
        $token   = trim((string)$request->input('token', ''));
        $verify  = (bool)$request->input('verify', false);

        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $name)) {
            return $this->serverNotice('Nome non valido (2-40 caratteri: lettere, numeri, . _ -)');
        }
        if (!preg_match('#^https://[^/\s]+#i', $url)) {
            return $this->serverNotice('URL non valido: deve iniziare con https:// (es. https://host:8443)');
        }
        if ($token === '') {
            return $this->serverNotice('Inserisci un trust token (incus config trust add <nome>)');
        }
        if ($repo->findByName($name) !== null) {
            return $this->serverNotice('Esiste già un server con questo nome');
        }

        // Percorsi dei certificati del server.
        $slug    = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        $dir     = rtrim($this->config['storage'], '/\\') . '/certs/' . $slug;
        $certPath = $dir . '/client.crt';
        $keyPath  = $dir . '/client.key';

        $clientCfg = [
            'https'       => rtrim($url, '/'),
            'client_cert' => $certPath,
            'client_key'  => $keyPath,
            'verify'      => $verify,
            'project'     => $project,
            'socket'      => null,
        ];

        try {
            $svc = new CertService(new IncusClient($clientCfg));
            $svc->generate($name, 3650, $certPath, $keyPath);
            $svc->install($certPath, 'vapor-' . $slug, $token);
        } catch (\Throwable $e) {
            // Pulizia dei file generati se la registrazione fallisce.
            @unlink($certPath);
            @unlink($keyPath);
            @rmdir($dir);
            return $this->serverNotice('Registrazione fallita: ' . $e->getMessage());
        }

        $id = $repo->create([
            'name'      => $name,
            'url'       => rtrim($url, '/'),
            'project'   => $project,
            'verify'    => $verify,
            'cert_path' => $certPath,
            'key_path'  => $keyPath,
        ]);
        $this->audit('server.create', $name, $url);

        // Seleziona subito il nuovo server.
        $_SESSION['vapor_server'] = $id;
        return $this->serverNotice('Server "' . $name . '" aggiunto e registrato.');
    }

    /** Imposta il server di default (admin). */
    public function setDefault(Request $request): Response
    {
        $this->requireAdmin();
        $id = (int)$request->input('id', 0);
        if ($this->repo()->find($id)) {
            $this->repo()->setDefault($id);
            $this->audit('server.default', (string)$id);
        }
        return $this->serverNotice('Server di default aggiornato.');
    }

    /** Elimina un server e i suoi certificati (admin). */
    public function destroy(Request $request): Response
    {
        $this->requireAdmin();
        $id   = (int)$request->input('id', 0);
        $repo = $this->repo();
        $row  = $repo->find($id);
        if ($row === null) {
            return $this->serverNotice('Server non trovato.');
        }
        $repo->delete($id);
        @unlink($row['cert_path']);
        @unlink($row['key_path']);
        @rmdir(dirname($row['cert_path']));
        $this->audit('server.delete', $row['name']);

        if (($_SESSION['vapor_server'] ?? null) == $id) {
            unset($_SESSION['vapor_server']);
        }
        // Se serviva un default e ne restano, promuovi il primo.
        if ($repo->default() !== null && $repo->count() > 0) {
            $repo->setDefault((int)$repo->default()['id']);
        }
        return $this->serverNotice('Server eliminato.');
    }

    /** Cambia il server attivo in sessione (qualsiasi utente). */
    public function switch(Request $request): Response
    {
        $id = (int)$request->input('id', 0);
        if ($this->repo()->find($id) === null) {
            throw new HttpException(404, 'Server non trovato.');
        }
        $_SESSION['vapor_server'] = $id;
        return $this->redirect('/instances');
    }

    private function serverNotice(string $msg): Response
    {
        return $this->redirect('/admin/servers?notice=' . rawurlencode($msg));
    }
}
