<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,mixed> $data Rend la vue puis l'enveloppe dans le gabarit. */
    public static function render(string $view, array $data = [], ?string $layout = 'layout'): string
    {
        $dir = dirname(__DIR__) . '/Views/';
        $content = self::include($dir . $view . '.php', $data);
        return $layout === null ? $content : self::include($dir . $layout . '.php', $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    private static function include(string $file, array $data): string
    {
        return (static function () use ($file, $data): string {
            $e = [self::class, 'e'];
            extract($data, EXTR_SKIP);
            ob_start();
            include $file;
            return (string) ob_get_clean();
        })();
    }
}
