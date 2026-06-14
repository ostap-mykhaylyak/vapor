<?php
namespace Vapor\Core;

/**
 * Token compatti firmati (stile JWT, HS256) per autorizzare l'apertura del
 * terminale sul server WebSocket, processo separato dal front controller.
 *
 * Il token è solo la metà "stateless" della sicurezza del terminale:
 * l'altra metà è il registro monouso su DB (vedi Auth::consumeTerminalToken)
 * e la ri-verifica della proprietà del container al momento della connessione.
 */
final class Token
{
    /**
     * Firma un set di claim. Aggiunge automaticamente iat ed exp.
     *
     * @param array $claims  deve già contenere un 'jti' univoco
     * @return string token "payload.signature" (base64url)
     */
    public static function sign(string $secret, array $claims, int $ttl = 60): string
    {
        $claims['iat'] = time();
        $claims['exp'] = time() + $ttl;

        $body = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig  = self::b64(hash_hmac('sha256', $body, $secret, true));
        return $body . '.' . $sig;
    }

    /**
     * Verifica firma e scadenza. Restituisce i claim oppure null.
     */
    public static function verify(string $secret, string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;

        $expected = self::b64(hash_hmac('sha256', $body, $secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $claims = json_decode(self::unb64($body), true);
        if (!is_array($claims)) {
            return null;
        }
        if (!isset($claims['exp']) || (int)$claims['exp'] < time()) {
            return null;
        }
        return $claims;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/')) ?: '';
    }
}
