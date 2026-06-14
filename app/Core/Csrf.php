<?php
namespace Vapor\Core;

/**
 * Protezione CSRF basata su token di sessione (double-submit/synchronizer).
 * La sessione deve essere già avviata.
 */
final class Csrf
{
    /** Restituisce (creandolo se serve) il token CSRF della sessione. */
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** Confronto a tempo costante con il token atteso. */
    public static function validate(?string $candidate): bool
    {
        $expected = $_SESSION['csrf'] ?? '';
        return $expected !== '' && is_string($candidate) && hash_equals($expected, $candidate);
    }

    /** Campo hidden pronto da inserire nei form. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::token() . '">';
    }
}
