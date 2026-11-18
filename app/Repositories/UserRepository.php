<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\AuthUser;
use PDO;

final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null ligne users + role_code (inclut password_hash : usage interne) */
    public function findByLogin(string $login): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.username = ? OR u.email = ? LIMIT 1'
        );
        $st->execute([$login, $login]);
        return $st->fetch() ?: null;
    }

    public function findAuthUser(int $id): ?AuthUser
    {
        $st = $this->pdo->prepare(
            'SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND u.is_active = 1'
        );
        $st->execute([$id]);
        $u = $st->fetch();
        return $u ? $this->toAuthUser($u) : null;
    }

    /** @param array<string,mixed> $u */
    public function toAuthUser(array $u): AuthUser
    {
        $st = $this->pdo->prepare(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ? ORDER BY p.code'
        );
        $st->execute([$u['role_id']]);
        return new AuthUser(
            (int) $u['id'], $u['username'], $u['email'], $u['first_name'], $u['last_name'],
            (int) $u['role_id'], $u['role_code'], $u['timezone'], $u['locale'],
            $st->fetchAll(PDO::FETCH_COLUMN),
            !empty($u['must_change_password'])
        );
    }

    /** @param array<string,mixed> $d clés : role_code, username, email, first_name, last_name, password_hash */
    public function create(array $d): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO users (role_id, username, email, first_name, last_name, password_hash, timezone)
             VALUES ((SELECT id FROM roles WHERE code = ?), ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$d['role_code'], $d['username'], $d['email'], $d['first_name'], $d['last_name'],
            $d['password_hash'], $d['timezone'] ?? 'Europe/Paris']);
        return (int) $this->pdo->lastInsertId();
    }

    /** Définit un nouveau mot de passe ; $mustChange impose son remplacement à la prochaine connexion. */
    public function setPassword(int $id, string $hash, bool $mustChange): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$hash, $mustChange ? 1 : 0, $id]);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function passwordHash(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$id]);
        $h = $st->fetchColumn();
        return $h === false ? null : (string) $h;
    }
}
