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
        $file = self::$viewPath . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View non trovata: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
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
