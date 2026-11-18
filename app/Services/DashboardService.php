<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuthUser;
use App\Repositories\AppointmentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\StatsRepository;
use App\Services\Notifications\ReminderPlanner;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Indicateurs du tableau de bord : uniquement des données réelles, limitées aux calendriers autorisés, avec les séries
 * dépliées en occurrences. « Total » compte les lignes (une série = un rendez-vous) ; tous les autres chiffres comptent des
 * occurrences. Les rendez-vous annulés ne comptent jamais dans les volumes d'activité.
 */
final class DashboardService
{
    public const PERIODS = ['week' => 'Cette semaine', 'month' => 'Ce mois', '30d' => '30 prochains jours', 'year' => 'Cette année'];
    public const STATUS_LABELS = ['scheduled' => 'Planifié', 'confirmed' => 'Confirmé', 'tentative' => 'Provisoire', 'completed' => 'Terminé', 'cancelled' => 'Annulé'];
    private const ACTIVE = ['scheduled', 'confirmed', 'tentative'];

    public function __construct(
        private PDO $pdo,
        private PermissionService $perms,
        private AppointmentRepository $appointments,
        private StatsRepository $stats,
        private NotificationRepository $notifications,
    ) {
    }

    /** @return array<string,mixed> */
    public function build(AuthUser $u, string $period = 'month', ?DateTimeImmutable $now = null): array
    {
        $period = isset(self::PERIODS[$period]) ? $period : 'month';
        $utc = new DateTimeZone('UTC');
        $tz = new DateTimeZone($u->timezone);
        $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $local = $now->setTimezone($tz);
        $today = $local->setTime(0, 0);
        $weekStart = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
        $monthStart = $today->modify('first day of this month');
        $win = fn (DateTimeImmutable $s, DateTimeImmutable $e) => $this->window($s, $e, $tz);
        $windows = [
            'today' => $win($today, $today->modify('+1 day')),
            'week' => $win($weekStart, $weekStart->modify('+7 days')),
            'month' => $win($monthStart, $monthStart->modify('+1 month')),
            'upcoming' => $win($local, $local->modify('+30 days')),
        ];
        $periods = [
            'week' => $windows['week'], 'month' => $windows['month'], '30d' => $windows['upcoming'],
            'year' => $win($today->setDate((int) $today->format('Y'), 1, 1), $today->setDate((int) $today->format('Y') + 1, 1, 1)),
        ];
        $chosen = $periods[$period];
        $days14 = $today->modify('+14 days');

        $ids = $this->perms->readableCalendarIds($u);
        $from = min($windows['today']['s'], $windows['week']['s'], $windows['month']['s'], $chosen['s'], $now);
        $to = max($windows['week']['e'], $windows['month']['e'], $chosen['e'], $windows['upcoming']['e'], $days14->setTimezone($utc));
        $res = $this->appointments->listRange($ids, $from, $to);
        $rows = $res['rows'];

        $active = fn (array $r) => in_array($r['status'], self::ACTIVE, true);
        $in = fn (array $w) => array_values(array_filter($rows, fn ($r) => self::overlaps($r, $w)));
        $count = fn (array $w) => count(array_filter($in($w), $active));

        // activité par statut et par catégorie sur la période choisie
        $inPeriod = $in($chosen);
        $byStatus = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        $cat = [];
        foreach ($inPeriod as $r) {
            $byStatus[$r['status']]++;
            if ($r['status'] === 'cancelled') {
                continue;
            }
            $k = $r['category_id'] === null ? 0 : (int) $r['category_id'];
            $cat[$k] ??= ['id' => $k ?: null, 'name' => $k ? $r['category_name'] : 'Sans catégorie', 'color' => $k ? $r['category_color'] : null, 'count' => 0];
            $cat[$k]['count']++;
        }
        usort($cat, fn ($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        // charge des 14 prochains jours
        $load = [];
        for ($i = 0; $i < 14; $i++) {
            $d = $today->modify("+$i days");
            $load[] = ['date' => $d->format('Y-m-d'), 'count' => $count($win($d, $d->modify('+1 day')))];
        }

        $up = $windows['upcoming'];
        $upcoming = array_values(array_filter($rows, fn ($r) => $active($r) && self::startsInWindow($r, $up, $local->format('Y-m-d'))));
        usort($upcoming, fn ($a, $b) => self::sortKey($a, $tz) <=> self::sortKey($b, $tz));
        $next = null;
        foreach ($upcoming as $r) {
            $next = $r;
            break;
        }

        $planner = new ReminderPlanner($this->pdo, new SettingsRepository($this->pdo));
        $iso = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d\TH:i:s\Z');
        return [
            'generated_at' => $iso($now), 'timezone' => $u->timezone, 'truncated' => $res['truncated'],
            'counts' => [
                'total' => $this->stats->totalAppointments($ids), 'today' => $count($windows['today']), 'week' => $count($windows['week']),
                'month' => $count($windows['month']), 'upcoming' => count($upcoming),
            ],
            'period' => [
                'key' => $period, 'label' => self::PERIODS[$period], 'start' => $iso($chosen['s']), 'end' => $iso($chosen['e']),
                'total' => count($inPeriod), 'active' => count(array_filter($inPeriod, $active)), 'completed' => $byStatus['completed'], 'cancelled' => $byStatus['cancelled'],
            ],
            'next' => $next === null ? null : AppointmentRepository::toApi($next),
            'notifications' => ['unread' => $this->notifications->unreadCount($u->id), 'reminders_24h' => count($planner->upcoming($u->id, $now, 1))],
            'by_status' => array_map(fn ($k) => ['status' => $k, 'label' => self::STATUS_LABELS[$k], 'count' => $byStatus[$k]], array_keys(self::STATUS_LABELS)),
            'by_category' => array_values($cat),
            'by_day' => $load,
            'calendars' => count($ids),
            'periods' => self::PERIODS,
        ];
    }

    /** @return array{s:DateTimeImmutable,e:DateTimeImmutable,sd:string,ed:string} bornes UTC et dates locales (pour les journées entières) */
    private function window(DateTimeImmutable $s, DateTimeImmutable $e, DateTimeZone $tz): array
    {
        $utc = new DateTimeZone('UTC');
        return ['s' => $s->setTimezone($utc), 'e' => $e->setTimezone($utc), 'sd' => $s->setTimezone($tz)->format('Y-m-d'), 'ed' => $e->setTimezone($tz)->format('Y-m-d')];
    }

    /** Chevauchement : instants pour les rendez-vous horaires, dates locales pour les journées entières (dates flottantes). @param array<string,mixed> $r @param array<string,mixed> $w */
    private static function overlaps(array $r, array $w): bool
    {
        if ($r['all_day']) {
            return substr($r['start_utc'], 0, 10) < $w['ed'] && substr($r['end_utc'], 0, 10) > $w['sd'];
        }
        $s = strtotime($r['start_utc'] . ' UTC');
        $e = strtotime($r['end_utc'] . ' UTC');
        return $e > $w['s']->getTimestamp() && $s < $w['e']->getTimestamp() || ($s === $e && $s >= $w['s']->getTimestamp() && $s < $w['e']->getTimestamp());
    }

    /** « À venir » : commence dans la fenêtre (pas encore commencé). @param array<string,mixed> $r @param array<string,mixed> $w */
    private static function startsInWindow(array $r, array $w, string $todayDate): bool
    {
        if ($r['all_day']) {
            $d = substr($r['start_utc'], 0, 10);
            return $d > $todayDate && $d < $w['ed']; // celle d'aujourd'hui est « en cours »
        }
        $s = strtotime($r['start_utc'] . ' UTC');
        return $s >= $w['s']->getTimestamp() && $s < $w['e']->getTimestamp();
    }

    /** @param array<string,mixed> $r */
    private static function sortKey(array $r, DateTimeZone $tz): int
    {
        if ($r['all_day']) { // minuit local de la date
            return (new DateTimeImmutable(substr($r['start_utc'], 0, 10) . ' 00:00:00', $tz))->getTimestamp();
        }
        return strtotime($r['start_utc'] . ' UTC');
    }
}
