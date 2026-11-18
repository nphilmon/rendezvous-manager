<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\PermissionService;
use App\Services\Recurrence\RecurrenceService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Calcule les rappels arrivés à échéance (ou à venir) : rendez-vous simples, journées entières et occurrences de séries.
 * Un rappel n'est créé qu'une fois (clé unique reminder + occurrence d'origine + canal), quel que soit le nombre d'exécutions.
 */
final class ReminderPlanner
{
    private const FMT = 'Y-m-d H:i:s';
    private const COLS = 'r.id AS reminder_id, r.user_id, r.channel, r.offset_minutes, a.id AS appointment_id, a.calendar_id, a.title, a.location,
        a.start_utc, a.end_utc, a.all_day, a.status, a.timezone, u.timezone AS user_tz';

    /** @var array<string,bool> */
    private array $readCache = [];

    public function __construct(private PDO $pdo, private SettingsRepository $settings)
    {
    }

    /**
     * Rappels dont l'heure de déclenchement est dans [from, to] et dont le rendez-vous n'est pas terminé à $notEndedBefore.
     * @return list<array<string,mixed>>
     */
    public function candidates(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $notEndedBefore, ?int $userId = null): array
    {
        $f = $from->format(self::FMT);
        $t = $to->format(self::FMT);
        $n = $notEndedBefore->format(self::FMT);
        $uFilter = $userId !== null ? ' AND r.user_id = ?' : '';
        $uArg = $userId !== null ? [$userId] : [];
        $out = [];
        $base = ' FROM reminders r JOIN appointments a ON a.id = r.appointment_id JOIN users u ON u.id = r.user_id
                  LEFT JOIN appointment_recurrence rec ON rec.appointment_id = a.id
                  WHERE a.deleted_at IS NULL AND a.status NOT IN (\'cancelled\', \'completed\') AND u.is_active = 1';

        // 1. rendez-vous simples à heure précise
        $st = $this->pdo->prepare('SELECT ' . self::COLS . $base . ' AND rec.id IS NULL AND a.all_day = 0
            AND DATE_SUB(a.start_utc, INTERVAL r.offset_minutes MINUTE) BETWEEN ? AND ? AND a.end_utc > ?' . $uFilter . ' ORDER BY a.start_utc LIMIT 2000');
        $st->execute(array_merge([$f, $t, $n], $uArg));
        foreach ($st->fetchAll() as $r) {
            $out[] = $this->item($r, $r['start_utc'], $r['start_utc'], $r['end_utc'], gmdate(self::FMT, strtotime($r['start_utc'] . ' UTC') - $r['offset_minutes'] * 60));
        }

        // 2. journées entières (dates flottantes) : le rappel se calcule à minuit dans le fuseau de l'utilisateur
        $st = $this->pdo->prepare('SELECT ' . self::COLS . $base . ' AND rec.id IS NULL AND a.all_day = 1
            AND a.start_utc BETWEEN DATE_SUB(DATE_ADD(?, INTERVAL r.offset_minutes MINUTE), INTERVAL 2 DAY) AND DATE_ADD(DATE_ADD(?, INTERVAL r.offset_minutes MINUTE), INTERVAL 2 DAY)' . $uFilter . ' LIMIT 2000');
        $st->execute(array_merge([$f, $t], $uArg));
        foreach ($st->fetchAll() as $r) {
            $trigger = self::allDayTrigger($r['start_utc'], (int) $r['offset_minutes'], $r['user_tz']);
            if ($trigger >= $f && $trigger <= $t && self::allDayEnd($r['end_utc'], $r['user_tz']) > $n) {
                $out[] = $this->item($r, $r['start_utc'], $r['start_utc'], $r['end_utc'], $trigger);
            }
        }

        // 3. séries : occurrences dépliées à la demande
        $st = $this->pdo->prepare('SELECT ' . self::COLS . ', rec.rrule, rec.dtstart_local, rec.timezone AS rec_tz ' . $base . ' AND rec.id IS NOT NULL
            AND a.start_utc <= DATE_ADD(?, INTERVAL r.offset_minutes MINUTE) AND (rec.until_utc IS NULL OR rec.until_utc >= DATE_ADD(?, INTERVAL r.offset_minutes MINUTE))' . $uFilter . ' LIMIT 500');
        $st->execute(array_merge([$t, $f], $uArg));
        $masters = $st->fetchAll();
        if ($masters) {
            $ids = array_values(array_unique(array_map(fn ($m) => (int) $m['appointment_id'], $masters)));
            $exc = $this->exceptions($ids);
            foreach ($masters as $m) {
                $allDay = (bool) $m['all_day'];
                $shift = (int) $m['offset_minutes'] * 60;
                $lo = $from->modify('-2 days')->modify("+$shift seconds");
                $hi = $to->modify('+2 days')->modify("+$shift seconds");
                foreach (RecurrenceService::expand($m, $exc[(int) $m['appointment_id']] ?? [], $lo, $hi, 200) as $o) {
                    $status = $o['overrides']['status'] ?? $m['status'];
                    if (in_array($status, ['cancelled', 'completed'], true)) {
                        continue;
                    }
                    $trigger = $allDay ? self::allDayTrigger($o['start'], (int) $m['offset_minutes'], $m['user_tz']) : gmdate(self::FMT, strtotime($o['start'] . ' UTC') - $shift);
                    $ended = $allDay ? self::allDayEnd($o['end'], $m['user_tz']) <= $n : $o['end'] <= $n;
                    if ($trigger >= $f && $trigger <= $t && !$ended) {
                        $item = $this->item($m, $o['original'], $o['start'], $o['end'], $trigger);
                        $item['title'] = $o['overrides']['title'] ?? $m['title'];
                        $item['location'] = $o['overrides']['location'] ?? $m['location'];
                        $out[] = $item;
                    }
                }
            }
        }
        usort($out, fn ($a, $b) => [$a['trigger'], $a['reminder_id']] <=> [$b['trigger'], $b['reminder_id']]);
        return $out;
    }

    /**
     * Crée les rappels dus (INSERT IGNORE sur la clé unique : aucune duplication, même avec plusieurs workers).
     * @return int nombre de rappels créés
     */
    public function plan(DateTimeImmutable $now): int
    {
        $cands = $this->candidates($now->modify('-24 hours'), $now, $now);
        if (!$cands) {
            return 0;
        }
        $prefs = $this->settings->notificationsFor(array_map(fn ($c) => $c['user_id'], $cands));
        $ins = $this->pdo->prepare(
            'INSERT IGNORE INTO notification_deliveries (reminder_id, appointment_id, user_id, channel, occurrence_start_utc, scheduled_at, status, title, body, created_at)
             VALUES (?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?)'
        );
        $created = 0;
        foreach ($cands as $c) {
            $p = $prefs[$c['user_id']];
            if (!$p['enabled'] || !in_array($c['channel'], $p['channels'], true) || !$this->canRead($c['user_id'], $c['calendar_id'])) {
                continue;
            }
            $grace = $p['missed'] === 'skip' ? 300 : 86400;
            if (strtotime($c['trigger'] . ' UTC') < $now->getTimestamp() - $grace) {
                continue;
            }
            [$title, $body] = NotificationText::make($c, $p['privacy']);
            $ins->execute([$c['reminder_id'], $c['appointment_id'], $c['user_id'], $c['channel'], $c['original'], $c['trigger'], $title, $body, $now->format(self::FMT)]);
            $created += $ins->rowCount();
        }
        return $created;
    }

    /**
     * Rappels à venir d'un utilisateur (aperçu « À venir » : calculés, non stockés).
     * @return list<array<string,mixed>>
     */
    public function upcoming(int $userId, DateTimeImmutable $now, int $days = 7): array
    {
        $prefs = $this->settings->notifications($userId);
        if (!$prefs['enabled']) {
            return [];
        }
        $out = [];
        foreach ($this->candidates($now, $now->modify("+$days days"), $now, $userId) as $c) {
            if ($c['channel'] === 'inapp' && $this->canRead($userId, $c['calendar_id'])) {
                [$title, $body] = NotificationText::make($c, $prefs['privacy']);
                $out[] = ['appointment_id' => $c['appointment_id'], 'occurrence_start' => $c['original'], 'trigger_at' => $c['trigger'], 'start' => $c['start'], 'offset_minutes' => $c['offset_minutes'],
                    'title' => $title, 'body' => $body, 'recurring' => $c['recurring']];
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function item(array $r, string $original, string $start, string $end, string $trigger): array
    {
        return ['reminder_id' => (int) $r['reminder_id'], 'user_id' => (int) $r['user_id'], 'channel' => $r['channel'], 'offset_minutes' => (int) $r['offset_minutes'],
            'appointment_id' => (int) $r['appointment_id'], 'calendar_id' => (int) $r['calendar_id'], 'title' => $r['title'], 'location' => $r['location'],
            'original' => $original, 'start' => $start, 'end' => $end, 'all_day' => (bool) $r['all_day'], 'trigger' => $trigger, 'user_tz' => $r['user_tz'], 'recurring' => !empty($r['rrule'])];
    }

    private static function allDayTrigger(string $startUtc, int $offset, string $tz): string
    {
        $local = new DateTimeImmutable(substr($startUtc, 0, 10) . ' 00:00:00', new DateTimeZone($tz));
        return gmdate(self::FMT, $local->getTimestamp() - $offset * 60);
    }

    private static function allDayEnd(string $endUtc, string $tz): string
    {
        return gmdate(self::FMT, (new DateTimeImmutable(substr($endUtc, 0, 10) . ' 00:00:00', new DateTimeZone($tz)))->getTimestamp());
    }

    /** L'utilisateur doit toujours pouvoir lire le calendrier (partage retiré = plus de rappel). */
    private function canRead(int $userId, int $calendarId): bool
    {
        $k = "$userId:$calendarId";
        if (!isset($this->readCache[$k])) {
            $u = (new UserRepository($this->pdo))->findAuthUser($userId);
            $this->readCache[$k] = $u !== null && (new PermissionService($this->pdo))->canAccessCalendar($u, $calendarId, 'read');
        }
        return $this->readCache[$k];
    }

    /** @param list<int> $ids @return array<int,array<string,array<string,mixed>>> */
    private function exceptions(array $ids): array
    {
        $st = $this->pdo->prepare('SELECT * FROM appointment_exceptions WHERE appointment_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $x) {
            $out[(int) $x['appointment_id']][$x['original_start_utc']] = $x;
        }
        return $out;
    }
}
