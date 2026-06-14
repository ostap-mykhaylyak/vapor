<?php
namespace Vapor\Core;

/**
 * Eccezione che mappa direttamente su un codice di stato HTTP
 * (es. 403 per violazione di proprietà, 404 per risorsa assente).
 */
class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message ?: ('HTTP ' . $status), $status);
    }
}
