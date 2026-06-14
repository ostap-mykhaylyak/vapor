<?php
namespace Vapor\Core;

/**
 * Header di sicurezza HTTP e nonce per la Content-Security-Policy.
 */
final class Security
{
    private static ?string $nonce = null;

    /** Nonce CSP, generato una volta per richiesta. */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    /**
     * Invia gli header di sicurezza. Va chiamato prima di emettere output.
     */
    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        $nonce = self::nonce();

        // Content-Security-Policy stretta: tutte le risorse sono self-hosted
        // (xterm.js incluso), nessuna CDN. Script inline solo col nonce.
        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'nonce-$nonce'",
            "connect-src 'self' ws: wss:",
        ]);

        header("Content-Security-Policy: $csp");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header_remove('X-Powered-By');
    }
}
