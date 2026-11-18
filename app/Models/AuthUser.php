<?php
declare(strict_types=1);

namespace App\Models;

final class AuthUser
{
    /** @param list<string> $permissions */
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly int $roleId,
        public readonly string $roleCode,
        public readonly string $timezone,
        public readonly string $locale,
        public readonly array $permissions = [],
        public readonly bool $mustChangePassword = false,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @return array<string,mixed> Jamais de hash de mot de passe. */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'username' => $this->username, 'email' => $this->email,
            'first_name' => $this->firstName, 'last_name' => $this->lastName,
            'role' => $this->roleCode, 'timezone' => $this->timezone, 'locale' => $this->locale,
            'permissions' => $this->permissions, 'credentials_expired' => $this->mustChangePassword,
        ];
    }
}
