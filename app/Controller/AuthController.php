<?php
namespace Vapor\Controller;

use Vapor\Core\Controller;
use Vapor\Core\Request;
use Vapor\Core\Response;
use Vapor\Auth\Auth;

/**
 * Login / logout della dashboard.
 */
class AuthController extends Controller
{
    private function auth(): Auth
    {
        return new Auth($this->config['auth']);
    }

    /** Mostra il form di login. */
    public function showLogin(Request $request): Response
    {
        $auth = $this->auth();
        $auth->startSession();
        if ($auth->check()) {
            return $this->redirect('/instances');
        }
        return $this->view('auth/login', ['error' => null], layout: 'layout_blank');
    }

    /** Elabora il login. */
    public function login(Request $request): Response
    {
        $auth = $this->auth();
        $auth->startSession();

        $ip = $this->clientIp();
        if ($auth->isLocked($ip)) {
            return $this->view('auth/login', [
                'error' => 'Troppi tentativi falliti. Riprova tra qualche minuto.',
            ], layout: 'layout_blank');
        }

        $username = trim((string)$request->input('username', ''));
        $password = (string)$request->input('password', '');

        if (!$auth->attempt($username, $password, $ip)) {
            $this->auditAs($username, 'auth.login', '', '', 'fail');
            return $this->view('auth/login', ['error' => 'Credenziali non valide.'], layout: 'layout_blank');
        }
        $this->auditAs($username, 'auth.login');
        return $this->redirect('/instances');
    }

    /** IP del client, considerando un eventuale reverse proxy fidato. */
    private function clientIp(): string
    {
        // X-Forwarded-For va considerato solo dietro proxy fidato; di default
        // usiamo l'IP della connessione diretta.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** Logout. */
    public function logout(Request $request): Response
    {
        $auth = $this->auth();
        $auth->startSession();
        $this->audit('auth.logout');
        $auth->logout();
        return $this->redirect('/login');
    }
}
