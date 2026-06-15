<?php
namespace Vapor\Core;

/**
 * Loader minimale di file .env (KEY=VALUE), senza dipendenze.
 *
 * Precedenza: il .env è la sorgente con priorità più alta e SOVRASCRIVE qualunque
 * variabile già presente nell'ambiente (systemd, shell, inline). Ciò che NON è
 * nel .env resta invariato.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // Supporta un eventuale prefisso "export ".
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // Rimuove eventuali apici/virgolette di contorno.
            if (strlen($val) >= 2
                && (($val[0] === '"' && $val[-1] === '"') || ($val[0] === "'" && $val[-1] === "'"))) {
                $val = substr($val, 1, -1);
            }

            if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }
            // Il .env ha priorità: sovrascrive sempre il valore esistente.
            putenv("$key=$val");
            $_ENV[$key]    = $val;
            $_SERVER[$key] = $val;
        }
    }
}
