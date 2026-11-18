<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static string $dir = '';
    /** @var array<string,array<string,mixed>> */
    private static array $cache = [];

    public static function init(string $dir): void
    {
        self::$dir = $dir;
        self::$cache = [];
    }

    /** Accès par "fichier.cle.sous_cle". */
    public static function get(string $path, mixed $default = null): mixed
    {
        $parts = explode('.', $path);
        $file = array_shift($parts);
        if (!isset(self::$cache[$file])) {
            $f = self::$dir . '/' . $file . '.php';
            self::$cache[$file] = is_file($f) ? (array) require $f : [];
        }
        $v = self::$cache[$file];
        foreach ($parts as $p) {
            if (!is_array($v) || !array_key_exists($p, $v)) {
                return $default;
            }
            $v = $v[$p];
        }
        return $v;
    }
}
