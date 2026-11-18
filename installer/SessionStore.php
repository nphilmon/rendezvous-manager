<?php
declare(strict_types=1);

namespace Installer;

interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function forget(string $key): void;
    /** Nouvel identifiant de session (contre la fixation). */
    public function regenerate(): void;
    public function destroy(): void;
}
