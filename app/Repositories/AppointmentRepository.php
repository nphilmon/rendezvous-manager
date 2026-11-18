<?php
declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use PDO;

final class AppointmentRepository
{
    public const MAX_ROWS = 2000;

    private const SELECT = "SELECT a.id, a.uid, a.calendar_id, a.title, a.description, a.notes, a.start_utc, a.end_utc, a.all_day, a.timezone,
                       a.location, a.address, a.status, a.priority, a.color, a.version, a.created_by, a.deleted_at, a.completed_at,
                       a.created_at, a.updated_at,
                       cat.id AS category_id, cat.name AS category_name, cat.color AS category_color, a.contact_id,
                       NULLIF(TRIM(CONCAT(IFNULL(ct.first_name, ''), ' ', ct.last_name)), '') AS contact_name,
                       EXISTS (SELECT 1 FROM appointment_recurrence r WHERE r.appointment_id = a.id) AS recurring
                FROM appointments a
                LEFT JOIN categories cat ON cat.id = a.category_id
                LEFT JOIN contacts ct ON ct.id = a.contact_id";

    private const SORTS = [
        'start' => 'a.start_utc', 'title' => 'a.title', 'created' => 'a.created_at', 'status' => 'a.status',
        'priority' => "FIELD(a.priority, 'low', 'normal', 'high', 'urgent')", 'updated' => 'a.updated_at',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, bool $withDeleted = false): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . ' WHERE a.id = ?' . ($withDeleted ? '' : ' AND a.deleted_at IS NULL'));
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Rendez-vous chevauchant [start, end) pour la grille. Séries récurrentes non dépliées (phase 9).
     * @param list<int> $calendarIds
     * @return array{rows:list<array<string,mixed>>,truncated:bool}
     */
    public function listRange(array $calendarIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($calendarIds === []) {
            return ['rows' => [], 'truncated' => false];
        }
        $in = implode(',', array_fill(0, count($calendarIds), '?'));
        $st = $this->pdo->prepare(self::SELECT . " WHERE a.deleted_at IS NULL AND a.calendar_id IN ($in) AND a.start_utc < ? AND a.end_utc > ?
                ORDER BY a.start_utc, a.end_utc, a.id LIMIT " . (self::MAX_ROWS + 1));
        $st->execute(array_merge($calendarIds, [$end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]));
        $rows = $st->fetchAll();
        return ['rows' => array_slice($rows, 0, self::MAX_ROWS), 'truncated' => count($rows) > self::MAX_ROWS];
    }

    /**
     * Recherche filtrée, triée et paginée.
     * Filtres : q, category_id, contact_id, status[], priority[], from, to (DateTimeImmutable UTC), created_by, mine_user_id, deleted.
     * @param list<int> $calendarIds calendriers déjà autorisés
     * @param array<string,mixed> $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(array $calendarIds, array $f, string $sort, string $dir, int $page, int $perPage): array
    {
        if ($calendarIds === []) {
            return ['rows' => [], 'total' => 0];
        }
        $w = [empty($f['deleted']) ? 'a.deleted_at IS NULL' : 'a.deleted_at IS NOT NULL'];
        $args = [];
        $w[] = 'a.calendar_id IN (' . implode(',', array_fill(0, count($calendarIds), '?')) . ')';
        array_push($args, ...$calendarIds);
        if (($f['q'] ?? '') !== '') {
            $like = '%' . addcslashes((string) $f['q'], '\\%_') . '%';
            $w[] = "(a.title LIKE ? ESCAPE '\\\\' OR a.description LIKE ? ESCAPE '\\\\' OR a.location LIKE ? ESCAPE '\\\\' OR a.address LIKE ? ESCAPE '\\\\')";
            array_push($args, $like, $like, $like, $like);
        }
        foreach (['category_id' => 'a.category_id', 'contact_id' => 'a.contact_id', 'created_by' => 'a.created_by'] as $k => $col) {
            if (!empty($f[$k])) {
                $w[] = "$col = ?";
                $args[] = (int) $f[$k];
            }
        }
        foreach (['status' => 'a.status', 'priority' => 'a.priority'] as $k => $col) {
            if (!empty($f[$k])) {
                $w[] = "$col IN (" . implode(',', array_fill(0, count($f[$k]), '?')) . ')';
                array_push($args, ...$f[$k]);
            }
        }
        if (!empty($f['mine_user_id'])) {
            $w[] = '(a.created_by = ? OR a.calendar_id IN (SELECT id FROM calendars WHERE owner_id = ?))';
            array_push($args, (int) $f['mine_user_id'], (int) $f['mine_user_id']);
        }
        if (!empty($f['from'])) {
            $w[] = 'a.end_utc > ?';
            $args[] = $f['from']->format('Y-m-d H:i:s');
        }
        if (!empty($f['to'])) {
            $w[] = 'a.start_utc < ?';
            $args[] = $f['to']->format('Y-m-d H:i:s');
        }
        $where = ' WHERE ' . implode(' AND ', $w);
        $order = (self::SORTS[$sort] ?? self::SORTS['start']) . ($dir === 'desc' ? ' DESC' : ' ASC') . ', a.id ' . ($dir === 'desc' ? 'DESC' : 'ASC');

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM appointments a' . $where);
        $st->execute($args);
        $total = (int) $st->fetchColumn();

        $st = $this->pdo->prepare(self::SELECT . $where . " ORDER BY $order LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage));
        $st->execute($args);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /**
     * Rendez-vous bloquants du même calendrier qui chevauchent [start, end).
     * Ne comptent pas : supprimés, annulés, « journée entière » (non bloquants), et le rendez-vous lui-même.
     * @return list<array<string,mixed>>
     */
    public function conflicts(int $calendarId, string $startUtc, string $endUtc, ?int $excludeId): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, title, start_utc, end_utc FROM appointments
             WHERE calendar_id = ? AND deleted_at IS NULL AND status <> 'cancelled' AND all_day = 0
               AND start_utc < ? AND end_utc > ? AND (? IS NULL OR id <> ?)
             ORDER BY start_utc LIMIT 10"
        );
        $st->execute([$calendarId, $endUtc, $startUtc, $excludeId, $excludeId]);
        return $st->fetchAll();
    }

    /** @param array<string,mixed> $d colonnes normalisées (voir AppointmentValidator) */
    public function insert(array $d, int $userId): int
    {
        $this->pdo->prepare(
            'INSERT INTO appointments (uid, calendar_id, category_id, contact_id, created_by, title, description, start_utc, end_utc, all_day,
                timezone, location, address, status, priority, color, notes, completed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            self::uuid(), $d['calendar_id'], $d['category_id'], $d['contact_id'], $userId, $d['title'], $d['description'],
            $d['start_utc'], $d['end_utc'], $d['all_day'], $d['timezone'], $d['location'], $d['address'], $d['status'],
            $d['priority'], $d['color'], $d['notes'], $d['status'] === 'completed' ? gmdate('Y-m-d H:i:s') : null,
            gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Mise à jour avec verrou optimiste. @return bool false si la version ne correspond plus */
    public function update(int $id, int $expectedVersion, array $d): bool
    {
        $st = $this->pdo->prepare(
            'UPDATE appointments SET calendar_id = ?, category_id = ?, contact_id = ?, title = ?, description = ?, start_utc = ?, end_utc = ?,
                all_day = ?, timezone = ?, location = ?, address = ?, status = ?, priority = ?, color = ?, notes = ?,
                completed_at = CASE WHEN ? = \'completed\' THEN COALESCE(completed_at, ?) ELSE NULL END,
                version = version + 1, updated_at = ?
             WHERE id = ? AND version = ? AND deleted_at IS NULL'
        );
        $now = gmdate('Y-m-d H:i:s');
        $st->execute([$d['calendar_id'], $d['category_id'], $d['contact_id'], $d['title'], $d['description'], $d['start_utc'], $d['end_utc'],
            $d['all_day'], $d['timezone'], $d['location'], $d['address'], $d['status'], $d['priority'], $d['color'], $d['notes'],
            $d['status'], $now, $now, $id, $expectedVersion]);
        return $st->rowCount() === 1;
    }

    public function softDelete(int $id, int $expectedVersion): bool
    {
        $st = $this->pdo->prepare('UPDATE appointments SET deleted_at = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ? AND deleted_at IS NULL');
        $now = gmdate('Y-m-d H:i:s');
        $st->execute([$now, $now, $id, $expectedVersion]);
        return $st->rowCount() === 1;
    }

    public function restore(int $id): bool
    {
        $st = $this->pdo->prepare('UPDATE appointments SET deleted_at = NULL, version = version + 1, updated_at = ? WHERE id = ? AND deleted_at IS NOT NULL');
        $st->execute([gmdate('Y-m-d H:i:s'), $id]);
        return $st->rowCount() === 1;
    }

    public function reminderFor(int $appointmentId, int $userId): ?int
    {
        $st = $this->pdo->prepare("SELECT MIN(offset_minutes) FROM reminders WHERE appointment_id = ? AND user_id = ? AND channel = 'inapp'");
        $st->execute([$appointmentId, $userId]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    }

    /** Remplace le rappel « dans l'application » de l'utilisateur (le moteur d'envoi arrive en phase 12). */
    public function setReminder(int $appointmentId, int $userId, ?int $minutes): void
    {
        $this->pdo->prepare("DELETE FROM reminders WHERE appointment_id = ? AND user_id = ? AND channel = 'inapp'")->execute([$appointmentId, $userId]);
        if ($minutes !== null) {
            $this->pdo->prepare("INSERT INTO reminders (appointment_id, user_id, offset_minutes, channel) VALUES (?, ?, ?, 'inapp')")
                ->execute([$appointmentId, $userId, $minutes]);
        }
    }

    public function recordChange(?int $calendarId, int $entityId, string $type, int $userId): void
    {
        $this->pdo->prepare('INSERT INTO sync_changes (calendar_id, entity_type, entity_id, change_type, changed_by, created_at) VALUES (?, \'appointment\', ?, ?, ?, ?)')
            ->execute([$calendarId, $entityId, $type, $userId, gmdate('Y-m-d H:i:s')]);
    }

    /**
     * Représentation API (dates ISO 8601 UTC « Z »). « Journée entière » : dates flottantes (minuit UTC, fin exclusive).
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function toApi(array $r, bool $detail = false): array
    {
        $iso = static fn (string $d): string => str_replace(' ', 'T', $d) . 'Z';
        $out = [
            'id' => (int) $r['id'], 'uid' => $r['uid'], 'calendar_id' => (int) $r['calendar_id'], 'title' => $r['title'],
            'description' => $r['description'], 'start' => $iso($r['start_utc']), 'end' => $iso($r['end_utc']),
            'all_day' => (bool) $r['all_day'], 'timezone' => $r['timezone'], 'location' => $r['location'], 'address' => $r['address'],
            'status' => $r['status'], 'priority' => $r['priority'], 'color' => $r['color'], 'version' => (int) $r['version'],
            'category' => $r['category_id'] === null ? null : ['id' => (int) $r['category_id'], 'name' => $r['category_name'], 'color' => $r['category_color']],
            'contact_id' => $r['contact_id'] === null ? null : (int) $r['contact_id'],
            'contact' => $r['contact_name'],
            'recurring' => (bool) $r['recurring'],
            'deleted' => $r['deleted_at'] !== null,
        ];
        if ($detail) {
            $out += ['notes' => $r['notes'], 'created_by' => (int) $r['created_by'], 'completed_at' => $r['completed_at'] ? $iso($r['completed_at']) : null,
                'created_at' => $iso($r['created_at']), 'updated_at' => $iso($r['updated_at'])];
        }
        return $out;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
