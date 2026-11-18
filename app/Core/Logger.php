<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    private const SENSITIVE = ['password', 'mot_de_passe', 'token', 'secret', 'authorization', 'cookie', 'private_key'];

    /** @param array<string,mixed> $context */
    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = (string) Config::get('app.paths.logs', '');
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
            error_log("[rvm] $level $message");
            return;
        }
        $line = sprintf(
            "%s [%s] %s %s\n",
            gmdate('c'),
            strtoupper($level),
            str_replace(["\r", "\n"], ' ', $message),
            $context ? json_encode(self::redact($context), JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::redact($v);
            } elseif (is_string($k)) {
                foreach (self::SENSITIVE as $s) {
                    if (stripos($k, $s) !== false) {
                        $data[$k] = '[masqué]';
                        break;
                    }
                }
            }
        }
        return $data;
    }
}
