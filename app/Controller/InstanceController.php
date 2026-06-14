<?php
namespace Vapor\Controller;

use Vapor\Core\Controller;
use Vapor\Core\Request;
use Vapor\Core\Response;

/**
 * CRUD e gestione stato delle istanze Incus.
 */
class InstanceController extends Controller
{
    /** Dashboard: elenco istanze (filtrate per proprietario). */
    public function index(Request $request): Response
    {
        $instances = $this->instances->ownedBy($this->username(), $this->isAdmin());
        if ($request->wantsJson()) {
            return $this->json($instances);
        }
        return $this->view('instances/index', ['instances' => $instances]);
    }

    /** Form di creazione. */
    public function create(Request $request): Response
    {
        return $this->view('instances/create', []);
    }

    /** Dettaglio istanza. */
    public function show(Request $request): Response
    {
        $name     = $request->param('name');
        $instance = $this->assertInstanceOwner($name);
        $state    = $this->instances->state($name);
        if ($request->wantsJson()) {
            return $this->json(['instance' => $instance, 'state' => $state]);
        }
        return $this->view('instances/show', [
            'instance' => $instance,
            'state'    => $state,
            'ws'       => $this->config['ws'],
        ]);
    }

    /** Salvataggio nuova istanza. */
    public function store(Request $request): Response
    {
        $data = $request->wantsJson() ? $request->json() : $request->post;
        $this->instances->create([
            'name'     => trim($data['name'] ?? ''),
            'image'    => trim($data['image'] ?? ''),
            'type'     => $data['type'] ?? 'container',
            'server'   => $data['server'] ?? null,
            'protocol' => $data['protocol'] ?? null,
            // Il creatore diventa proprietario del container.
            'owner'    => $this->username(),
        ]);

        $this->audit('instance.create', (string)($data['name'] ?? ''), 'image=' . ($data['image'] ?? ''));

        // Avvio automatico opzionale.
        if (!empty($data['start'])) {
            $this->instances->start($data['name']);
        }

        if ($request->wantsJson()) {
            return $this->json(['ok' => true], 201);
        }
        return $this->redirect('/instances/' . rawurlencode($data['name']));
    }

    /** Cambio stato: start/stop/restart/freeze/unfreeze. */
    public function action(Request $request): Response
    {
        $name   = $request->param('name');
        $action = $request->param('action');
        $allowed = ['start', 'stop', 'restart', 'freeze', 'unfreeze'];
        if (!in_array($action, $allowed, true)) {
            return $this->json(['error' => 'Azione non valida'], 400);
        }
        $this->assertInstanceOwner($name);
        $this->instances->{$action}($name);
        $this->audit('instance.' . $action, $name);

        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'action' => $action]);
        }
        return $this->redirect('/instances/' . rawurlencode($name));
    }

    /** Aggiornamento configurazione. */
    public function update(Request $request): Response
    {
        $name = $request->param('name');
        $this->assertInstanceOwner($name);
        $data = $request->wantsJson() ? $request->json() : $request->post;
        $config = [];
        if (isset($data['config']) && is_array($data['config'])) {
            // I non-admin non possono riassegnare la proprietà né toccare le
            // chiavi di sicurezza dell'istanza.
            if (!$this->isAdmin()) {
                foreach (array_keys($data['config']) as $k) {
                    if ($k === \Vapor\Incus\InstanceService::OWNER_KEY || str_starts_with((string)$k, 'security.')) {
                        unset($data['config'][$k]);
                    }
                }
            }
            $config['config'] = $data['config'];
        }
        $this->instances->update($name, $config);
        $this->audit('instance.update', $name);
        return $request->wantsJson()
            ? $this->json(['ok' => true])
            : $this->redirect('/instances/' . rawurlencode($name));
    }

    /** Eliminazione istanza. */
    public function destroy(Request $request): Response
    {
        $name = $request->param('name');
        $this->assertInstanceOwner($name);
        // Tenta lo stop forzato prima dell'eliminazione, ignorando errori.
        try { $this->instances->stop($name); } catch (\Throwable) {}
        $this->instances->delete($name);
        $this->audit('instance.delete', $name);

        return $request->wantsJson()
            ? Response::noContent()
            : $this->redirect('/instances');
    }
}
