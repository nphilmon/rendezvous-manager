<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        self::$vars = array_merge(self::$vars, self::parse((string) file_get_contents($file)));
    }

    /** @return array<string,string> */
    public static function parse(string $content): array
    {
        $out = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }

    public static function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        $v = getenv($key);
        return $v === false ? $default : $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower(self::get($key, $default ? 'true' : 'false'));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, (string) $default);
        return is_numeric($v) ? (int) $v : $default;
    }
}
