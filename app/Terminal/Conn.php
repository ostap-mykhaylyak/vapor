<?php
namespace Vapor\Terminal;

/**
 * Wrapper su uno stream socket dentro il loop del terminal server.
 */
class Conn
{
    public string $rbuf = '';   // buffer di lettura (handshake + frame parziali)
    public string $wbuf = '';   // buffer di scrittura non ancora svuotato
    public bool $handshaked = false;
    public ?Session $session = null;

    /**
     * @param resource $stream
     * @param string   $role   'browser' | 'data' | 'control'
     */
    public function __construct(public mixed $stream, public string $role)
    {
    }

    public function id(): int
    {
        return (int)$this->stream;
    }
}
