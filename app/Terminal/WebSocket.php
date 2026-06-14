<?php
namespace Vapor\Terminal;

/**
 * Implementazione minimale del protocollo WebSocket (RFC 6455) in PHP puro:
 * handshake (lato server e lato client) e codifica/decodifica dei frame.
 * Nessuna dipendenza esterna.
 */
final class WebSocket
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public const OP_CONT  = 0x0;
    public const OP_TEXT  = 0x1;
    public const OP_BIN   = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING  = 0x9;
    public const OP_PONG  = 0xA;

    /* ------------------------------------------------------------------ */
    /*  Handshake                                                          */
    /* ------------------------------------------------------------------ */

    /** Valore di Sec-WebSocket-Accept a partire dalla chiave del client. */
    public static function acceptKey(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    /** Risposta di handshake lato server (verso il browser). */
    public static function serverHandshake(string $clientKey): string
    {
        $accept = self::acceptKey($clientKey);
        return "HTTP/1.1 101 Switching Protocols\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Accept: $accept\r\n\r\n";
    }

    /**
     * Richiesta di handshake lato client (verso Incus).
     *
     * @return array{0:string,1:string}  [richiesta HTTP, chiave inviata]
     */
    public static function clientHandshake(string $path, string $host): array
    {
        $key = base64_encode(random_bytes(16));
        $req = "GET $path HTTP/1.1\r\n"
             . "Host: $host\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Key: $key\r\n"
             . "Sec-WebSocket-Version: 13\r\n\r\n";
        return [$req, $key];
    }

    /* ------------------------------------------------------------------ */
    /*  Framing                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Codifica un frame WebSocket.
     *
     * @param bool $mask true quando agiamo da client (i frame client->server
     *                   DEVONO essere mascherati, es. verso Incus).
     */
    public static function encode(string $payload, int $opcode = self::OP_BIN, bool $mask = false): string
    {
        $frame = chr(0x80 | ($opcode & 0x0F)); // FIN = 1
        $len   = strlen($payload);
        $maskBit = $mask ? 0x80 : 0x00;

        if ($len < 126) {
            $frame .= chr($maskBit | $len);
        } elseif ($len < 65536) {
            $frame .= chr($maskBit | 126) . pack('n', $len);
        } else {
            $frame .= chr($maskBit | 127) . pack('J', $len);
        }

        if ($mask) {
            $key   = random_bytes(4);
            $frame .= $key;
            $frame .= self::xorMask($payload, $key);
        } else {
            $frame .= $payload;
        }
        return $frame;
    }

    /**
     * Estrae tutti i frame completi presenti nel buffer, consumandoli.
     * I frame incompleti restano nel buffer per il giro successivo.
     *
     * @param string $buffer  buffer di ricezione (modificato per riferimento)
     * @return array<int,array{opcode:int,fin:bool,payload:string}>
     */
    public static function decode(string &$buffer): array
    {
        $messages = [];

        while (true) {
            $bufLen = strlen($buffer);
            if ($bufLen < 2) {
                break;
            }

            $b1     = ord($buffer[0]);
            $b2     = ord($buffer[1]);
            $fin    = ($b1 & 0x80) !== 0;
            $opcode = $b1 & 0x0F;
            $masked = ($b2 & 0x80) !== 0;
            $len    = $b2 & 0x7F;
            $offset = 2;

            if ($len === 126) {
                if ($bufLen < 4) break;
                $len    = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                if ($bufLen < 10) break;
                $len    = unpack('J', substr($buffer, 2, 8))[1];
                $offset = 10;
            }

            $maskKey = '';
            if ($masked) {
                if ($bufLen < $offset + 4) break;
                $maskKey = substr($buffer, $offset, 4);
                $offset += 4;
            }

            if ($bufLen < $offset + $len) {
                break; // frame non ancora completo
            }

            $payload = substr($buffer, $offset, $len);
            if ($masked) {
                $payload = self::xorMask($payload, $maskKey);
            }
            $buffer = substr($buffer, $offset + $len);

            $messages[] = ['opcode' => $opcode, 'fin' => $fin, 'payload' => $payload];
        }

        return $messages;
    }

    /** XOR del payload con la chiave di mascheramento a 4 byte. */
    private static function xorMask(string $payload, string $key): string
    {
        $len    = strlen($payload);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $key[$i & 3];
        }
        return $masked;
    }
}
