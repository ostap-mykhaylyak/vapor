<?php
namespace Vapor\Incus;

/**
 * Avvia una sessione exec interattiva (PTY) su un'istanza Incus.
 *
 * Incus gestisce direttamente lo pseudo-terminale lato kernel: noi creiamo
 * l'operation con wait-for-websocket=true e interactive=true, e otteniamo
 * gli "fds" (segreti) per collegare i WebSocket di dati e di controllo.
 */
class ExecService
{
    public function __construct(private IncusClient $incus) {}

    /**
     * Crea l'operation exec e restituisce id + segreti dei fd.
     *
     * @return array{id:string,fds:array<string,string>}
     */
    public function start(string $instance, array $opts = []): array
    {
        $body = [
            'command'      => $opts['command'] ?? ['/bin/sh', '-c', 'exec $(command -v bash || echo /bin/sh)'],
            'wait-for-websocket' => true,
            'interactive'  => true,
            'environment'  => array_merge([
                'TERM' => 'xterm-256color',
                'HOME' => '/root',
            ], $opts['environment'] ?? []),
            'width'        => (int)($opts['width'] ?? 80),
            'height'       => (int)($opts['height'] ?? 24),
            'user'         => (int)($opts['user'] ?? 0),
            'group'        => (int)($opts['group'] ?? 0),
        ];

        // Non attendiamo il completamento: l'operation resta in esecuzione
        // finché la sessione interattiva è aperta.
        $op = $this->incus->post(
            '/1.0/instances/' . rawurlencode($instance) . '/exec',
            $body,
            wait: false
        );

        $id  = $op['id'] ?? null;
        $fds = $op['metadata']['fds'] ?? ($op['fds'] ?? []);
        if (!$id || empty($fds)) {
            throw new IncusException('Avvio exec fallito: operation senza fds');
        }

        return ['id' => $id, 'fds' => $fds];
    }

    /**
     * Invia un resize del PTY tramite il canale di controllo
     * (in alternativa al messaggio JSON sul control socket).
     */
    public function resizeControlPayload(int $width, int $height): string
    {
        return json_encode([
            'command' => 'window-resize',
            'args'    => ['width' => (string)$width, 'height' => (string)$height],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
