<?php
namespace Vapor\Controller;

use Vapor\Core\Controller;
use Vapor\Core\Request;
use Vapor\Core\Response;

/**
 * Gestione dei network forward (port forwarding lato rete Incus).
 */
class ForwardController extends Controller
{
    /** Elenco forward, opzionalmente filtrati per rete. */
    public function index(Request $request): Response
    {
        $this->requireAdmin();
        $networks = $this->forwards->networks();
        // Considera solo reti gestite che supportano i forward (bridge/ovn).
        $managed  = array_values(array_filter($networks, fn($n) => ($n['managed'] ?? false) === true));

        $selected = $request->input('network', $managed[0]['name'] ?? null);
        $forwards = $selected ? $this->forwards->all($selected) : [];

        if ($request->wantsJson()) {
            return $this->json(['network' => $selected, 'forwards' => $forwards]);
        }
        return $this->view('forwards/index', [
            'networks' => $managed,
            'selected' => $selected,
            'forwards' => $forwards,
        ]);
    }

    /** Crea un nuovo forward (con eventuale prima regola di porta). */
    public function store(Request $request): Response
    {
        $this->requireAdmin();
        $data    = $request->wantsJson() ? $request->json() : $request->post;
        $network = $data['network'] ?? '';

        $ports = [];
        if (!empty($data['listen_port']) && !empty($data['target_address'])) {
            $ports[] = [
                'protocol'       => $data['protocol'] ?? 'tcp',
                'listen_port'    => (string)$data['listen_port'],
                'target_address' => $data['target_address'],
                'target_port'    => (string)($data['target_port'] ?? $data['listen_port']),
            ];
        }

        $this->forwards->create($network, [
            'listen_address' => $data['listen_address'] ?? '',
            'description'    => $data['description'] ?? '',
            'ports'          => $ports,
        ]);

        return $request->wantsJson()
            ? $this->json(['ok' => true], 201)
            : $this->redirect('/forwards?network=' . rawurlencode($network));
    }

    /** Aggiunge una regola di porta a un forward esistente. */
    public function addPort(Request $request): Response
    {
        $this->requireAdmin();
        $data    = $request->wantsJson() ? $request->json() : $request->post;
        $network = $data['network'] ?? '';
        $listen  = $data['listen_address'] ?? '';

        $this->forwards->addPort($network, $listen, [
            'protocol'       => $data['protocol'] ?? 'tcp',
            'listen_port'    => (string)($data['listen_port'] ?? ''),
            'target_address' => $data['target_address'] ?? '',
            'target_port'    => (string)($data['target_port'] ?? $data['listen_port'] ?? ''),
        ]);

        return $request->wantsJson()
            ? $this->json(['ok' => true])
            : $this->redirect('/forwards?network=' . rawurlencode($network));
    }

    /** Elimina un forward. */
    public function destroy(Request $request): Response
    {
        $this->requireAdmin();
        $data    = $request->wantsJson() ? $request->json() : $request->post;
        $network = $data['network'] ?? $request->input('network', '');
        $listen  = $data['listen_address'] ?? $request->input('listen_address', '');

        $this->forwards->delete($network, $listen);

        return $request->wantsJson()
            ? Response::noContent()
            : $this->redirect('/forwards?network=' . rawurlencode($network));
    }
}
