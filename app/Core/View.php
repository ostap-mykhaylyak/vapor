<?php
namespace Vapor\Core;

/**
 * Rendering di template PHP con layout.
 */
class View
{
    private static string $viewPath = __DIR__ . '/../views';

    /**
     * Renderizza una view dentro il layout indicato.
     */
    public static function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = self::partial($template, $data);
        if ($layout === '') {
            return $content;
        }
        return self::partial($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * Renderizza un template senza layout.
     */
    public static function partial(string $template, array $data = []): string
    {
        // Nome "oscuro" per non collidere con le chiavi dei dati della view
        // (es. una view che usa $file): extract() con EXTR_SKIP non deve
        // saltare le variabili della view a causa dei locali di questo metodo.
        $__vapor_view_file = self::$viewPath . '/' . $template . '.php';
        if (!is_file($__vapor_view_file)) {
            throw new \RuntimeException("View non trovata: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $__vapor_view_file;
        return ob_get_clean() ?: '';
    }

    /**
     * Escape HTML.
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
