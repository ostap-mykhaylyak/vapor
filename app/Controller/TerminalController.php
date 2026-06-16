<?php
namespace Vapor\Controller;

use Vapor\Core\Controller;
use Vapor\Core\Request;
use Vapor\Core\Response;
use Vapor\Core\Token;
use Vapor\Auth\Auth;

/**
 * Pagina del terminale PTY (xterm.js) e generazione del token di sessione.
 *
 * Sicurezza del terminale (difesa in profondità):
 *   1. solo il proprietario del container (o un admin) può ottenere un token;
 *   2. il token è firmato (HS256) e contiene username, ruolo, istanza, jti;
 *   3. il jti è registrato su DB e consumato una sola volta dal server WS;
 *   4. TTL breve (60s);
 *   5. il server WS ri-verifica la proprietà contro Incus alla connessione.
 */
class TerminalController extends Controller
{
    private function auth(): Auth
    {
        return new Auth($this->config['auth']);
    }

    /** Pagina con xterm.js a tutto schermo. */
    public function show(Request $request): Response
    {
        $instance = $request->param('name');
        $this->assertInstanceOwner($instance);

        return $this->view('terminal/show', [
            'instance' => $instance,
            'token'    => $this->issueToken($instance),
            'wsUrl'    => $this->config['ws']['public_url'],
        ], layout: 'layout_blank');
    }

    /** Endpoint AJAX per ottenere un nuovo token (riconnessione). */
    public function token(Request $request): Response
    {
        $instance = $request->param('name');
        $this->assertInstanceOwner($instance);

        return $this->json([
            'token' => $this->issueToken($instance),
            'wsUrl' => $this->config['ws']['public_url'],
        ]);
    }

    /**
     * Emette un token firmato e lo registra come monouso su DB.
     */
    private function issueToken(string $instance): string
    {
        $ttl   = 60;
        $jti   = bin2hex(random_bytes(16));
        $user  = $this->user();

        $token = Token::sign($this->config['app']['secret'], [
            'jti'  => $jti,
            'sub'  => $user['username'],
            'role' => $user['role'] ?? 'user',
            'inst' => $instance,
            'srv'  => (int)($_SESSION['vapor_server'] ?? 0),  // server attivo
        ], $ttl);

        $this->auth()->storeTerminalToken($jti, $user['username'], $instance, time() + $ttl);
        $this->audit('terminal.token', $instance, 'jti=' . $jti);

        return $token;
    }
}
