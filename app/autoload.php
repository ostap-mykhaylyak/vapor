<?php
/**
 * Autoloader PSR-4 autonomo per il namespace Vapor\ (nessuna dipendenza
 * da Composer). Mappa Vapor\Foo\Bar -> app/Foo/Bar.php.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Vapor\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
