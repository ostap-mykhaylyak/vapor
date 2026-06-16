<?php
/**
 * Definizione delle rotte di Vapor.
 */

use Vapor\Core\Router;
use Vapor\Controller\InstanceController;
use Vapor\Controller\ForwardController;
use Vapor\Controller\FileController;
use Vapor\Controller\TerminalController;
use Vapor\Controller\AuthController;
use Vapor\Controller\AdminController;
use Vapor\Controller\ServerController;

return function (Router $r): void {
    // --- Autenticazione ---
    $r->get('/login',   [AuthController::class, 'showLogin']);
    $r->post('/login',  [AuthController::class, 'login']);
    $r->post('/logout', [AuthController::class, 'logout']);

    // --- Server Incus/LXD ---
    $r->post('/servers/switch',         [ServerController::class, 'switch']);   // qualsiasi utente
    $r->get('/admin/servers',           [ServerController::class, 'index']);    // admin
    $r->post('/admin/servers',          [ServerController::class, 'store']);
    $r->post('/admin/servers/default',  [ServerController::class, 'setDefault']);
    $r->post('/admin/servers/delete',   [ServerController::class, 'destroy']);

    // --- Gestione utenti (solo admin) ---
    $r->get('/admin/users',           [AdminController::class, 'users']);
    $r->post('/admin/users',          [AdminController::class, 'createUser']);
    $r->post('/admin/users/role',     [AdminController::class, 'setRole']);
    $r->post('/admin/users/password', [AdminController::class, 'resetPassword']);
    $r->post('/admin/users/delete',   [AdminController::class, 'deleteUser']);
    $r->get('/admin/audit',           [AdminController::class, 'audit']);

    // Home -> elenco istanze.
    $r->get('/', [InstanceController::class, 'index']);

    // --- Istanze (CRUD + stato) ---
    $r->get('/instances',                 [InstanceController::class, 'index']);
    $r->get('/instances/create',          [InstanceController::class, 'create']);
    $r->post('/instances',                [InstanceController::class, 'store']);
    $r->get('/instances/{name}',          [InstanceController::class, 'show']);
    $r->put('/instances/{name}',          [InstanceController::class, 'update']);
    $r->post('/instances/{name}',         [InstanceController::class, 'update']);
    $r->delete('/instances/{name}',       [InstanceController::class, 'destroy']);
    $r->post('/instances/{name}/{action}',[InstanceController::class, 'action']);

    // --- Network forward ---
    $r->get('/forwards',                  [ForwardController::class, 'index']);
    $r->post('/forwards',                 [ForwardController::class, 'store']);
    $r->post('/forwards/port',            [ForwardController::class, 'addPort']);
    $r->delete('/forwards',               [ForwardController::class, 'destroy']);
    $r->post('/forwards/delete',          [ForwardController::class, 'destroy']);

    // --- File manager ---
    $r->get('/instances/{name}/files',        [FileController::class, 'browse']);
    $r->get('/instances/{name}/files/read',   [FileController::class, 'read']);
    $r->post('/instances/{name}/files/write', [FileController::class, 'write']);
    $r->post('/instances/{name}/files/mkdir', [FileController::class, 'mkdir']);
    $r->post('/instances/{name}/files/upload',[FileController::class, 'upload']);
    $r->post('/instances/{name}/files/delete',[FileController::class, 'destroy']);

    // --- Terminale PTY ---
    $r->get('/instances/{name}/terminal',       [TerminalController::class, 'show']);
    $r->post('/instances/{name}/terminal/token', [TerminalController::class, 'token']);
};
