<?php
declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use App\Services\Recurrence\RRule;
use App\Services\Recurrence\RecurrenceService;
use DateTimeZone;
use PDO;

final class AppointmentRepository
{
    public const MAX_ROWS = 2000;

    private const COLS = "a.id, a.uid, a.calendar_id, a.title, a.description, a.notes, a.all_day, a.timezone,
                       a.location, a.location_type, a.address, a.status, a.priority, a.color, a.version, a.created_by, a.deleted_at, a.completed_at,
                       a.created_at, a.updated_at,
                       cat.id AS category_id, cat.name AS category_name, cat.color AS category_color, a.contact_id,
                       NULLIF(TRIM(CONCAT(IFNULL(ct.first_name, ''), ' ', ct.last_name)), '') AS contact_name,
                       (r.id IS NOT NULL) AS recurring, r.rrule, r.dtstart_local, r.timezone AS rec_tz";

    private const JOINS = "FROM appointments a
                LEFT JOIN categories cat ON cat.id = a.category_id
                LEFT JOIN contacts ct ON ct.id = a.contact_id
                LEFT JOIN appointment_recurrence r ON r.appointment_id = a.id";

    private static function select(bool $occ = false): string
    {
        return 'SELECT ' . self::COLS . ($occ
            ? ', COALESCE(occ.s, a.start_utc) AS start_utc, COALESCE(occ.e, a.end_utc) AS end_utc, occ.o AS occurrence_start'
            : ', a.start_utc, a.end_utc, NULL AS occurrence_start') . ' ' . self::JOINS;
    }

    private const SORTS = [
        'start' => 'COALESCE(occ.s, a.start_utc)', 'title' => 'a.title', 'created' => 'a.created_at', 'status' => 'a.status',
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
        $st = $this->pdo->prepare(self::select() . ' WHERE a.id = ?' . ($withDeleted ? '' : ' AND a.deleted_at IS NULL'));
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Rendez-vous chevauchant [start, end) pour la grille. Les séries sont dépliées en occurrences (jamais stockées) ;
     * chaque occurrence porte occurrence_start (début d'origine, clé d'exception).
     * @param list<int> $calendarIds
     * @return array{rows:list<array<string,mixed>>,truncated:bool}
     */
    public function listRange(array $calendarIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($calendarIds === []) {
            return ['rows' => [], 'truncated' => false];
        }
        $in = implode(',', array_fill(0, count($calendarIds), '?'));
        $s = $start->format('Y-m-d H:i:s');
        $e = $end->format('Y-m-d H:i:s');
        $st = $this->pdo->prepare(self::select() . " WHERE a.deleted_at IS NULL AND r.id IS NULL AND a.calendar_id IN ($in) AND a.start_utc < ? AND a.end_utc > ?
                ORDER BY a.start_utc, a.end_utc, a.id LIMIT " . (self::MAX_ROWS + 1));
        $st->execute(array_merge($calendarIds, [$e, $s]));
        $rows = $st->fetchAll();

        $st = $this->pdo->prepare(self::select() . " WHERE a.deleted_at IS NULL AND r.id IS NOT NULL AND a.calendar_id IN ($in) AND (
                (a.start_utc < ? AND (r.until_utc IS NULL OR DATE_ADD(r.until_utc, INTERVAL TIMESTAMPDIFF(SECOND, a.start_utc, a.end_utc) SECOND) > ?))
                OR EXISTS (SELECT 1 FROM appointment_exceptions x WHERE x.appointment_id = a.id AND x.exception_type = 'modified' AND x.new_start_utc < ? AND x.new_end_utc > ?))
                LIMIT 500");
        $st->execute(array_merge($calendarIds, [$e, $s, $e, $s]));
        $masters = $st->fetchAll();
        $exc = $this->exceptionsFor(array_map(fn ($m) => (int) $m['id'], $masters));
        foreach ($masters as $m) {
            foreach (RecurrenceService::expand($m, $exc[(int) $m['id']] ?? [], $start, $end, 1000) as $o) {
                $rows[] = self::occurrenceRow($m, $o);
            }
        }
        usort($rows, fn ($a, $b) => [$a['start_utc'], $a['end_utc'], $a['id']] <=> [$b['start_utc'], $b['end_utc'], $b['id']]);
        return ['rows' => array_slice($rows, 0, self::MAX_ROWS), 'truncated' => count($rows) > self::MAX_ROWS];
    }

    /**
     * Rendez-vous à exporter : une ligne par rendez-vous (une série reste une seule ligne maître), supprimés exclus.
     * Une série est retenue si elle peut avoir une occurrence dans la période. $limit + 1 lignes au plus (le dépassement est détecté par l'appelant).
     * @param list<int> $calendarIds @return list<array<string,mixed>>
     */
    public function exportRows(array $calendarIds, ?string $fromUtc, ?string $toUtc, ?string $status, int $limit): array
    {
        if ($calendarIds === []) {
            return [];
        }
        $w = ['a.deleted_at IS NULL', 'a.calendar_id IN (' . implode(',', array_fill(0, count($calendarIds), '?')) . ')'];
        $args = $calendarIds;
        if ($status !== null) {
            $w[] = 'a.status = ?';
            $args[] = $status;
        }
        if ($toUtc !== null) {
            $w[] = 'a.start_utc < ?';
            $args[] = $toUtc;
        }
        if ($fromUtc !== null) {
            $w[] = '(CASE WHEN r.id IS NULL THEN a.end_utc > ? ELSE (r.until_utc IS NULL OR r.until_utc >= ?) END)';
            array_push($args, $fromUtc, $fromUtc);
        }
        $st = $this->pdo->prepare(self::select() . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY a.start_utc, a.id LIMIT ' . ($limit + 1));
        $st->execute($args);
        return $st->fetchAll();
    }

    /** @param array<string,mixed> $master @param array{original:string,start:string,end:string,overrides:array<string,mixed>} $o @return array<string,mixed> */
    public static function occurrenceRow(array $master, array $o): array
    {
        return array_replace($master, $o['overrides'], ['start_utc' => $o['start'], 'end_utc' => $o['end'], 'occurrence_start' => $o['original']]);
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

        // Avec une période, chaque série est représentée par sa première occurrence dans la période.
        $timed = !empty($f['from']) || !empty($f['to']);
        $occ = $timed ? $this->firstOccurrences($w, $args, $f) : [];
        $occSql = '(SELECT NULL AS id, NULL AS s, NULL AS e, NULL AS o) occ';
        $occArgs = [];
        if ($occ) {
            $parts = [];
            foreach ($occ as $id => $o) {
                $parts[] = $parts ? 'SELECT ?, ?, ?, ?' : 'SELECT ? AS id, ? AS s, ? AS e, ? AS o';
                array_push($occArgs, $id, $o['start'], $o['end'], $o['original']);
            }
            $occSql = '(' . implode(' UNION ALL ', $parts) . ') occ';
        }
        $timeArgs = [];
        if ($timed) {
            $t = [];
            if (!empty($f['from'])) {
                $t[] = 'a.end_utc > ?';
                $timeArgs[] = $f['from']->format('Y-m-d H:i:s');
            }
            if (!empty($f['to'])) {
                $t[] = 'a.start_utc < ?';
                $timeArgs[] = $f['to']->format('Y-m-d H:i:s');
            }
            $w[] = '(occ.id IS NOT NULL OR (r.id IS NULL AND ' . implode(' AND ', $t) . '))';
        }
        $join = self::JOINS . " LEFT JOIN $occSql ON occ.id = a.id";
        $where = ' WHERE ' . implode(' AND ', $w);
        $all = array_merge($occArgs, $args, $timeArgs);
        $order = (self::SORTS[$sort] ?? self::SORTS['start']) . ($dir === 'desc' ? ' DESC' : ' ASC') . ', a.id ' . ($dir === 'desc' ? 'DESC' : 'ASC');

        $st = $this->pdo->prepare('SELECT COUNT(*) ' . $join . $where);
        $st->execute($all);
        $total = (int) $st->fetchColumn();

        $sql = 'SELECT ' . self::COLS . ', COALESCE(occ.s, a.start_utc) AS start_utc, COALESCE(occ.e, a.end_utc) AS end_utc, occ.o AS occurrence_start ' . $join
            . $where . " ORDER BY $order LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage);
        $st = $this->pdo->prepare($sql);
        $st->execute($all);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /**
     * Première occurrence (dans la période demandée) de chaque série correspondant aux filtres.
     * @param list<string> $w @param list<mixed> $args @param array<string,mixed> $f
     * @return array<int,array{original:string,start:string,end:string,overrides:array<string,mixed>,exception:bool}>
     */
    private function firstOccurrences(array $w, array $args, array $f): array
    {
        $st = $this->pdo->prepare(self::select() . ' WHERE ' . implode(' AND ', $w) . ' AND r.id IS NOT NULL LIMIT 500');
        $st->execute($args);
        $masters = $st->fetchAll();
        $exc = $this->exceptionsFor(array_map(fn ($m) => (int) $m['id'], $masters));
        $out = [];
        foreach ($masters as $m) {
            $from = $f['from'] ?? new DateTimeImmutable($m['start_utc'], new DateTimeZone('UTC'));
            $to = $f['to'] ?? $from->modify('+5 years');
            $o = RecurrenceService::expand($m, $exc[(int) $m['id']] ?? [], $from, $to, 5);
            if ($o) {
                $out[(int) $m['id']] = $o[0];
            }
        }
        return $out;
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
               AND NOT EXISTS (SELECT 1 FROM appointment_recurrence r WHERE r.appointment_id = appointments.id)
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
                timezone, location, location_type, address, status, priority, color, notes, completed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            self::uuid(), $d['calendar_id'], $d['category_id'], $d['contact_id'], $userId, $d['title'], $d['description'],
            $d['start_utc'], $d['end_utc'], $d['all_day'], $d['timezone'], $d['location'], $d['location_type'], $d['address'], $d['status'],
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
                all_day = ?, timezone = ?, location = ?, location_type = ?, address = ?, status = ?, priority = ?, color = ?, notes = ?,
                completed_at = CASE WHEN ? = \'completed\' THEN COALESCE(completed_at, ?) ELSE NULL END,
                version = version + 1, updated_at = ?
             WHERE id = ? AND version = ? AND deleted_at IS NULL'
        );
        $now = gmdate('Y-m-d H:i:s');
        $st->execute([$d['calendar_id'], $d['category_id'], $d['contact_id'], $d['title'], $d['description'], $d['start_utc'], $d['end_utc'],
            $d['all_day'], $d['timezone'], $d['location'], $d['location_type'], $d['address'], $d['status'], $d['priority'], $d['color'], $d['notes'],
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
        $st = $this->pdo->prepare('SELECT MIN(offset_minutes) FROM reminders WHERE appointment_id = ? AND user_id = ?');
        $st->execute([$appointmentId, $userId]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    }

    /**
     * Remplace le rappel de l'utilisateur : une ligne par canal activé dans ses réglages (exécutées par le moteur de
     * notifications, voir NotificationEngine).
     * @param list<string> $channels
     */
    public function setReminder(int $appointmentId, int $userId, ?int $minutes, array $channels = ['inapp']): void
    {
        $this->pdo->prepare('DELETE FROM reminders WHERE appointment_id = ? AND user_id = ?')->execute([$appointmentId, $userId]);
        if ($minutes !== null) {
            $ins = $this->pdo->prepare('INSERT INTO reminders (appointment_id, user_id, offset_minutes, channel) VALUES (?, ?, ?, ?)');
            foreach ($channels ?: ['inapp'] as $ch) {
                $ins->execute([$appointmentId, $userId, $minutes, $ch]);
            }
        }
    }

    // ---- Récurrence et exceptions ------------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function recurrence(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM appointment_recurrence WHERE appointment_id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $rule règle normalisée (RRule::parse) */
    public function saveRecurrence(int $id, array $rule, string $dtstartLocal, string $tz): void
    {
        $this->pdo->prepare(
            'INSERT INTO appointment_recurrence (appointment_id, rrule, dtstart_local, timezone, until_utc, occurrence_count)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rrule = VALUES(rrule), dtstart_local = VALUES(dtstart_local), timezone = VALUES(timezone),
                                     until_utc = VALUES(until_utc), occurrence_count = VALUES(occurrence_count)'
        )->execute([$id, RRule::format($rule), $dtstartLocal, $tz, $rule['until'], $rule['count']]);
    }

    public function deleteRecurrence(int $id): void
    {
        $this->pdo->prepare('DELETE FROM appointment_exceptions WHERE appointment_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM appointment_recurrence WHERE appointment_id = ?')->execute([$id]);
    }

    /** @param list<int> $ids @return array<int,array<string,array<string,mixed>>> exceptions par série puis par début d'origine */
    public function exceptionsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT * FROM appointment_exceptions WHERE appointment_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $x) {
            $out[(int) $x['appointment_id']][$x['original_start_utc']] = $x;
        }
        return $out;
    }

    /** @param array<string,mixed>|null $overrides */
    public function upsertException(int $id, string $original, string $type, ?string $newStart, ?string $newEnd, ?array $overrides): void
    {
        $this->pdo->prepare(
            'INSERT INTO appointment_exceptions (appointment_id, original_start_utc, exception_type, new_start_utc, new_end_utc, overrides)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE exception_type = VALUES(exception_type), new_start_utc = VALUES(new_start_utc),
                                     new_end_utc = VALUES(new_end_utc), overrides = VALUES(overrides)'
        )->execute([$id, $original, $type, $newStart, $newEnd, $overrides ? json_encode($overrides, JSON_UNESCAPED_UNICODE) : null]);
    }

    public function deleteException(int $id, string $original): void
    {
        $this->pdo->prepare('DELETE FROM appointment_exceptions WHERE appointment_id = ? AND original_start_utc = ?')->execute([$id, $original]);
    }

    public function deleteExceptionsFrom(int $id, string $original): void
    {
        $this->pdo->prepare('DELETE FROM appointment_exceptions WHERE appointment_id = ? AND original_start_utc >= ?')->execute([$id, $original]);
    }

    /** Incrémente la version sans autre modification (changement porté par une exception). */
    public function touch(int $id, int $expectedVersion): bool
    {
        $st = $this->pdo->prepare('UPDATE appointments SET version = version + 1, updated_at = ? WHERE id = ? AND version = ? AND deleted_at IS NULL');
        $st->execute([gmdate('Y-m-d H:i:s'), $id, $expectedVersion]);
        return $st->rowCount() === 1;
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
            'all_day' => (bool) $r['all_day'], 'timezone' => $r['timezone'], 'location' => $r['location'], 'location_type' => $r['location_type'], 'address' => $r['address'],
            'status' => $r['status'], 'priority' => $r['priority'], 'color' => $r['color'], 'version' => (int) $r['version'],
            'category' => $r['category_id'] === null ? null : ['id' => (int) $r['category_id'], 'name' => $r['category_name'], 'color' => $r['category_color']],
            'contact_id' => $r['contact_id'] === null ? null : (int) $r['contact_id'],
            'contact' => $r['contact_name'],
            'recurring' => (bool) $r['recurring'],
            'occurrence_start' => !empty($r['occurrence_start']) ? $iso($r['occurrence_start']) : null,
            'deleted' => $r['deleted_at'] !== null,
        ];
        if ($detail) {
            if (!empty($r['rrule'])) {
                $out['rrule'] = $r['rrule'];
                $out['recurrence'] = RRule::toInput(RRule::parse($r['rrule']), new DateTimeZone($r['all_day'] ? 'UTC' : ($r['rec_tz'] ?: $r['timezone'])));
            }
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
