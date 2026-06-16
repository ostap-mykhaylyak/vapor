<?php
namespace Vapor\Controller;

use Vapor\Core\Controller;
use Vapor\Core\Request;
use Vapor\Core\Response;
use Vapor\Auth\Auth;
use Vapor\Audit\Audit;

/**
 * Gestione utenti (riservata agli amministratori).
 */
class AdminController extends Controller
{
    private function auth(): Auth
    {
        return new Auth($this->config['auth']);
    }

    /** Registro di audit (solo admin). */
    public function auditLog(Request $request): Response
    {
        $this->requireAdmin();
        $user   = trim((string)$request->input('user', ''));
        $action = trim((string)$request->input('action', ''));
        $events = (new Audit($this->config['auth']))->recent(300, $user ?: null, $action ?: null);

        return $this->view('admin/audit', [
            'events'  => $events,
            'fUser'   => $user,
            'fAction' => $action,
        ]);
    }

    /** Elenco utenti + form di creazione. */
    public function users(Request $request): Response
    {
        $this->requireAdmin();
        $notice = $request->input('notice');
        if (is_string($notice)) {
            // Sanifica eventuale input riflesso nella notice (difesa extra).
            $notice = preg_replace('/[^\p{L}\p{N} ()+._-]/u', '', $notice);
        }
        return $this->view('admin/users', [
            'users'  => $this->auth()->listUsers(),
            'notice' => $notice,
        ]);
    }

    /** Crea o aggiorna un utente. */
    public function createUser(Request $request): Response
    {
        $this->requireAdmin();
        $username = trim((string)$request->input('username', ''));
        $password = (string)$request->input('password', '');
        $role     = $request->input('role') === 'admin' ? 'admin' : 'user';

        if (!preg_match('/^[a-zA-Z0-9._-]{2,32}$/', $username)) {
            return $this->redirect('/admin/users?notice=Username+non+valido');
        }
        if (strlen($password) < 8) {
            return $this->redirect('/admin/users?notice=Password+troppo+corta+(min+8)');
        }
        $this->auth()->createUser($username, $password, $role);
        $this->audit('user.create', $username, 'role=' . $role);
        return $this->redirect('/admin/users?notice=Utente+salvato');
    }

    /** Cambia il ruolo di un utente. */
    public function setRole(Request $request): Response
    {
        $this->requireAdmin();
        $username = (string)$request->input('username', '');
        $role     = (string)$request->input('role', 'user');

        // Impedisce di togliersi da soli l'ultimo accesso admin.
        if ($username === $this->username() && $role !== 'admin') {
            return $this->redirect('/admin/users?notice=Non+puoi+rimuovere+il+tuo+ruolo+admin');
        }
        $this->auth()->setRole($username, $role);
        $this->audit('user.role', $username, 'role=' . $role);
        return $this->redirect('/admin/users?notice=Ruolo+aggiornato');
    }

    /** Reimposta la password di un utente. */
    public function resetPassword(Request $request): Response
    {
        $this->requireAdmin();
        $username = (string)$request->input('username', '');
        $password = (string)$request->input('password', '');
        if (strlen($password) < 8) {
            return $this->redirect('/admin/users?notice=Password+troppo+corta+(min+8)');
        }
        $this->auth()->setPassword($username, $password);
        $this->audit('user.password', $username);
        return $this->redirect('/admin/users?notice=Password+aggiornata');
    }

    /** Elimina un utente. */
    public function deleteUser(Request $request): Response
    {
        $this->requireAdmin();
        $username = (string)$request->input('username', '');
        if ($username === $this->username()) {
            return $this->redirect('/admin/users?notice=Non+puoi+eliminare+te+stesso');
        }
        $this->auth()->deleteUser($username);
        $this->audit('user.delete', $username);
        return $this->redirect('/admin/users?notice=Utente+eliminato');
    }
}
