<?php
namespace Vapor\Terminal;

/**
 * Una sessione di terminale: collega la connessione del browser ai due
 * WebSocket verso Incus (canale dati PTY e canale di controllo).
 */
class Session
{
    public ?Conn $data    = null;   // fd "0": stdin/stdout del PTY
    public ?Conn $control = null;   // canale di controllo (resize/segnali)

    public function __construct(public Conn $browser, public string $instance)
    {
    }
}
