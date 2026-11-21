<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CalendarRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<int> $ids calendriers déjà autorisés en lecture
     * @return list<array<string,mixed>>
     */
    public function listByIds(array $ids, bool $withVersion = false): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT c.id, c.name, c.description, c.color, c.kind, c.visibility, c.timezone, c.owner_id, c.version,
                    CONCAT(u.first_name, ' ', u.last_name) AS owner_name
             FROM calendars c JOIN users u ON u.id = c.owner_id
             WHERE c.deleted_at IS NULL AND c.id IN ($in) ORDER BY c.name, c.id"
        );
        $st->execute($ids);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) AS owner_name FROM calendars c JOIN users u ON u.id = c.owner_id WHERE c.id = ? AND c.deleted_at IS NULL"
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Crée « Mon calendrier » (personnel, privé) si l'utilisateur n'a aucun calendrier. @return int|null identifiant créé, null s'il en avait déjà un */
    public function ensureDefault(int $ownerId, string $timezone): ?int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM calendars WHERE owner_id = ? AND deleted_at IS NULL');
        $st->execute([$ownerId]);
        if ((int) $st->fetchColumn() > 0) {
            return null;
        }
        return $this->insert($ownerId, ['name' => 'Mon calendrier', 'description' => null, 'color' => '#3b82f6', 'kind' => 'personal', 'visibility' => 'private', 'timezone' => $timezone]);
    }

    /** @param array<string,mixed> $d */
    public function insert(int $ownerId, array $d): int
    {
        $this->pdo->prepare('INSERT INTO calendars (owner_id, name, description, color, kind, visibility, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ownerId, $d['name'], $d['description'], $d['color'], $d['kind'], $d['visibility'], $d['timezone'], gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $d @return bool false si la version fournie ne correspond plus */
    public function update(int $id, array $d, ?int $expectedVersion): bool
    {
        $st = $this->pdo->prepare(
            'UPDATE calendars SET name = ?, description = ?, color = ?, kind = ?, visibility = ?, timezone = ?, version = version + 1, updated_at = ?
             WHERE id = ? AND deleted_at IS NULL AND (? IS NULL OR version = ?)'
        );
        $st->execute([$d['name'], $d['description'], $d['color'], $d['kind'], $d['visibility'], $d['timezone'], gmdate('Y-m-d H:i:s'), $id, $expectedVersion, $expectedVersion]);
        return $st->rowCount() === 1;
    }

    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE calendars SET deleted_at = ?, updated_at = ?, version = version + 1 WHERE id = ? AND deleted_at IS NULL')->execute([gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $id]);
    }

    /** @return list<array<string,mixed>> */
    public function permissions(int $calendarId): array
    {
        $st = $this->pdo->prepare(
            "SELECT p.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name, u.username, p.can_read, p.can_create, p.can_update, p.can_delete, p.can_share
             FROM calendar_permissions p JOIN users u ON u.id = p.user_id WHERE p.calendar_id = ? ORDER BY u.last_name, u.first_name, u.id"
        );
        $st->execute([$calendarId]);
        return $st->fetchAll();
    }

    /** Remplace l'ensemble des partages. @param list<array<string,mixed>> $grants */
    public function replacePermissions(int $calendarId, array $grants): void
    {
        $this->pdo->prepare('DELETE FROM calendar_permissions WHERE calendar_id = ?')->execute([$calendarId]);
        $ins = $this->pdo->prepare('INSERT INTO calendar_permissions (calendar_id, user_id, can_read, can_create, can_update, can_delete, can_share) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($grants as $g) {
            $ins->execute([$calendarId, $g['user_id'], $g['can_read'], $g['can_create'], $g['can_update'], $g['can_delete'], $g['can_share']]);
        }
    }

    /** @return list<array<string,mixed>> utilisateurs actifs dont le nom ou l'identifiant correspond (aucune adresse e-mail renvoyée) */
    public function lookupUsers(string $q, int $exceptId): array
    {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $st = $this->pdo->prepare(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS name, username FROM users
             WHERE is_active = 1 AND id <> ? AND (username LIKE ? ESCAPE '\\\\' OR first_name LIKE ? ESCAPE '\\\\' OR last_name LIKE ? ESCAPE '\\\\' OR CONCAT(first_name, ' ', last_name) LIKE ? ESCAPE '\\\\')
             ORDER BY last_name, first_name LIMIT 20"
        );
        $st->execute([$exceptId, $like, $like, $like, $like]);
        return $st->fetchAll();
    }

    /** @param list<int> $ids @return list<int> identifiants d'utilisateurs actifs parmi $ids */
    public function activeUserIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT id FROM users WHERE is_active = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}
