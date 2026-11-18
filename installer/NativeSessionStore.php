<?php
declare(strict_types=1);

namespace Installer;

/** Session PHP dédiée à l'installation, stockée dans storage/sessions (hors zone publique). */
final class NativeSessionStore implements SessionStore
{
    public function __construct(string $savePath, bool $secure)
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!is_dir($savePath)) {
                mkdir($savePath, 0700, true);
            }
            session_save_path($savePath);
            session_name('rvm_install');
            session_set_cookie_params(['lifetime' => 0, 'path' => '/install', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.gc_maxlifetime', '7200');
            session_start();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}
