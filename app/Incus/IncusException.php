<?php
namespace Vapor\Incus;

class IncusException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 0, ?\Throwable $prev = null)
    {
        parent::__construct($message, $statusCode, $prev);
    }
}
