<?php
declare(strict_types=1);

namespace Installer;

/** Implémentation en mémoire (tests). */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string,mixed> */
    private array $d = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->d[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->d[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->d[$key]);
    }

    public function regenerate(): void
    {
    }

    public function destroy(): void
    {
        $this->d = [];
    }
}
