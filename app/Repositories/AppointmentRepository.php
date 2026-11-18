<?php
declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use PDO;

final class AppointmentRepository
{
    public const MAX_ROWS = 2000;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Rendez-vous chevauchant [start, end) dans les calendriers donnés (déjà autorisés).
     * Les séries récurrentes ne sont pas encore dépliées (phase 9) : seule l'occurrence d'origine est renvoyée,
     * avec recurring=true.
     * @param list<int> $calendarIds
     * @return array{rows:list<array<string,mixed>>,truncated:bool}
     */
    public function listRange(array $calendarIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($calendarIds === []) {
            return ['rows' => [], 'truncated' => false];
        }
        $in = implode(',', array_fill(0, count($calendarIds), '?'));
        $sql = "SELECT a.id, a.uid, a.calendar_id, a.title, a.description, a.start_utc, a.end_utc, a.all_day, a.timezone,
                       a.location, a.address, a.status, a.priority, a.color, a.version,
                       cat.id AS category_id, cat.name AS category_name, cat.color AS category_color,
                       NULLIF(TRIM(CONCAT(IFNULL(ct.first_name, ''), ' ', ct.last_name)), '') AS contact_name,
                       EXISTS (SELECT 1 FROM appointment_recurrence r WHERE r.appointment_id = a.id) AS recurring
                FROM appointments a
                LEFT JOIN categories cat ON cat.id = a.category_id
                LEFT JOIN contacts ct ON ct.id = a.contact_id
                WHERE a.deleted_at IS NULL AND a.calendar_id IN ($in)
                  AND a.start_utc < ? AND a.end_utc > ?
                ORDER BY a.start_utc, a.end_utc, a.id
                LIMIT " . (self::MAX_ROWS + 1);
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge($calendarIds, [$end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]));
        $rows = $st->fetchAll();
        $truncated = count($rows) > self::MAX_ROWS;
        return ['rows' => array_slice($rows, 0, self::MAX_ROWS), 'truncated' => $truncated];
    }

    /**
     * Représentation API (dates ISO 8601 en UTC, « Z »).
     * Rendez-vous « journée entière » : start/end sont des dates flottantes (minuit UTC, fin exclusive),
     * à lire comme des dates et non comme des instants.
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function toApi(array $r): array
    {
        $iso = static fn (string $d): string => str_replace(' ', 'T', $d) . 'Z';
        return [
            'id' => (int) $r['id'], 'uid' => $r['uid'], 'calendar_id' => (int) $r['calendar_id'], 'title' => $r['title'],
            'description' => $r['description'], 'start' => $iso($r['start_utc']), 'end' => $iso($r['end_utc']),
            'all_day' => (bool) $r['all_day'], 'timezone' => $r['timezone'], 'location' => $r['location'], 'address' => $r['address'],
            'status' => $r['status'], 'priority' => $r['priority'], 'color' => $r['color'], 'version' => (int) $r['version'],
            'category' => $r['category_id'] === null ? null : ['id' => (int) $r['category_id'], 'name' => $r['category_name'], 'color' => $r['category_color']],
            'contact' => $r['contact_name'],
            'recurring' => (bool) $r['recurring'],
        ];
    }
}
