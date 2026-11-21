<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Models\AuthUser;
use App\Repositories\AppointmentRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Rapports d'activité : statistiques sur les rendez-vous d'une période (au plus 366 jours), calculées dans le fuseau de l'utilisateur,
 * limitées aux calendriers qu'il peut lire, séries dépliées en occurrences. Un rendez-vous compte dans la période où il COMMENCE.
 */
final class ReportService
{
    public const MAX_DAYS = 366;
    private const WEEKDAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public function __construct(private PDO $pdo, private PermissionService $perms, private AppointmentRepository $appointments)
    {
    }

    /** @return array<string,mixed> */
    public function build(AuthUser $u, ?string $from, ?string $to, ?DateTimeImmutable $now = null): array
    {
        $tz = new DateTimeZone($u->timezone);
        $utc = new DateTimeZone('UTC');
        $today = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($tz)->setTime(0, 0);
        $parse = static function (?string $v, string $name) use ($tz): ?DateTimeImmutable {
            if ($v === null || $v === '') {
                return null;
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new HttpException(422, "« $name » : date AAAA-MM-JJ attendue.");
            }
            return new DateTimeImmutable($v . ' 00:00:00', $tz);
        };
        $start = $parse($from, 'from') ?? $today->modify('first day of this month');
        $endIncl = $parse($to, 'to') ?? $start->modify('last day of this month');
        if ($endIncl < $start) {
            throw new HttpException(422, '« to » doit être postérieur ou égal à « from ».');
        }
        $endExcl = $endIncl->modify('+1 day');
        if ((int) $start->diff($endExcl)->days > self::MAX_DAYS) {
            throw new HttpException(422, 'Période trop longue (' . self::MAX_DAYS . ' jours maximum).');
        }
        $sUtc = $start->setTimezone($utc);
        $eUtc = $endExcl->setTimezone($utc);
        $sDate = $start->format('Y-m-d');
        $eDate = $endExcl->format('Y-m-d');

        $ids = $this->perms->readableCalendarIds($u);
        $res = $this->appointments->listRange($ids, $sUtc->modify('-1 day'), $eUtc);
        $rows = array_values(array_filter($res['rows'], static function (array $r) use ($sUtc, $eUtc, $sDate, $eDate): bool {
            if ($r['all_day']) {
                $d = substr($r['start_utc'], 0, 10);
                return $d >= $sDate && $d < $eDate;
            }
            $t = strtotime($r['start_utc'] . ' UTC');
            return $t >= $sUtc->getTimestamp() && $t < $eUtc->getTimestamp();
        }));

        $names = $this->names('calendars', 'name', $ids);
        $creators = $this->userNames(array_unique(array_map(static fn ($r) => (int) $r['created_by'], $rows)));
        $count = static function (array &$m, string $k): void {
            $m[$k] = ($m[$k] ?? 0) + 1;
        };
        $byStatus = array_fill_keys(['scheduled', 'confirmed', 'tentative', 'completed', 'cancelled'], 0);
        $byPriority = array_fill_keys(['low', 'normal', 'high', 'urgent'], 0);
        $byCat = $byCal = $byUser = $byMonth = $byContact = [];
        $byWeekday = array_fill(0, 7, 0);
        $byHour = array_fill(0, 24, 0);
        $minutes = 0;
        $allDay = 0;
        foreach ($rows as $r) {
            $byStatus[$r['status']]++;
            $byPriority[$r['priority']]++;
            $count($byCat, $r['category_id'] === null ? '' : (string) $r['category_name']);
            $count($byCal, (string) ($names[(int) $r['calendar_id']] ?? '?'));
            $count($byUser, $creators[(int) $r['created_by']] ?? 'Compte supprimé');
            if (!empty($r['contact_name'])) {
                $count($byContact, (string) $r['contact_name']);
            }
            if ($r['all_day']) {
                $allDay++;
                $d = new DateTimeImmutable(substr($r['start_utc'], 0, 10), $utc);
                $byWeekday[(int) $d->format('N') - 1]++;
                $count($byMonth, $d->format('Y-m'));
                continue;
            }
            $l = (new DateTimeImmutable($r['start_utc'], $utc))->setTimezone($tz);
            $byWeekday[(int) $l->format('N') - 1]++;
            $byHour[(int) $l->format('G')]++;
            $count($byMonth, $l->format('Y-m'));
            if ($r['status'] !== 'cancelled') {
                $minutes += (int) ((strtotime($r['end_utc'] . ' UTC') - strtotime($r['start_utc'] . ' UTC')) / 60);
            }
        }
        $total = count($rows);
        $rank = static function (array $m, int $limit = 0): array {
            arsort($m);
            $out = [];
            foreach ($m as $k => $v) {
                $out[] = ['label' => (string) $k, 'count' => $v];
            }
            return $limit > 0 ? array_slice($out, 0, $limit) : $out;
        };
        ksort($byMonth);
        $iso = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d');
        return [
            'period' => ['from' => $iso($start), 'to' => $iso($endIncl), 'timezone' => $u->timezone, 'days' => (int) $start->diff($endExcl)->days],
            'truncated' => $res['truncated'],
            'totals' => [
                'appointments' => $total, 'active' => $total - $byStatus['cancelled'] - $byStatus['completed'], 'completed' => $byStatus['completed'], 'cancelled' => $byStatus['cancelled'],
                'completed_rate' => $total === 0 ? null : round(100 * $byStatus['completed'] / $total, 1), 'cancelled_rate' => $total === 0 ? null : round(100 * $byStatus['cancelled'] / $total, 1),
                'hours' => round($minutes / 60, 1), 'all_day' => $allDay, 'calendars' => count($ids),
            ],
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'by_category' => array_map(static fn ($x) => ['label' => $x['label'] === '' ? 'Sans catégorie' : $x['label'], 'count' => $x['count']], $rank($byCat)),
            'by_calendar' => $rank($byCal),
            'by_creator' => $rank($byUser),
            'by_weekday' => array_map(static fn ($i) => ['label' => self::WEEKDAYS[$i], 'count' => $byWeekday[$i]], array_keys(self::WEEKDAYS)),
            'by_hour' => array_map(static fn ($h) => ['label' => sprintf('%02d h', $h), 'count' => $byHour[$h]], array_keys($byHour)),
            'by_month' => array_map(static fn ($k, $v) => ['label' => (string) $k, 'count' => $v], array_keys($byMonth), array_values($byMonth)),
            'top_contacts' => $rank($byContact, 10),
        ];
    }

    /** @param array<string,mixed> $report @return list<list<string>> lignes « Rubrique, Libellé, Nombre » pour l'export CSV */
    public static function toRows(array $report): array
    {
        $rows = [['Rubrique', 'Libellé', 'Nombre']];
        $t = $report['totals'];
        foreach (['appointments' => 'Rendez-vous', 'active' => 'À venir ou en cours', 'completed' => 'Terminés', 'cancelled' => 'Annulés', 'all_day' => 'Journées entières'] as $k => $label) {
            $rows[] = ['Total', $label, (string) $t[$k]];
        }
        $rows[] = ['Total', 'Heures planifiées (hors annulés, hors journées entières)', (string) $t['hours']];
        $statusLabels = ['scheduled' => 'Planifié', 'confirmed' => 'Confirmé', 'tentative' => 'Provisoire', 'completed' => 'Terminé', 'cancelled' => 'Annulé'];
        $prioLabels = ['low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute', 'urgent' => 'Urgente'];
        foreach ($report['by_status'] as $k => $n) {
            $rows[] = ['Statut', $statusLabels[$k], (string) $n];
        }
        foreach ($report['by_priority'] as $k => $n) {
            $rows[] = ['Priorité', $prioLabels[$k], (string) $n];
        }
        foreach (['by_category' => 'Catégorie', 'by_calendar' => 'Calendrier', 'by_creator' => 'Créé par', 'by_weekday' => 'Jour de la semaine', 'by_hour' => 'Heure de début', 'by_month' => 'Mois', 'top_contacts' => 'Contact'] as $key => $label) {
            foreach ($report[$key] as $x) {
                $rows[] = [$label, $x['label'], (string) $x['count']];
            }
        }
        return $rows;
    }

    /** @param list<int> $ids @return array<int,string> */
    private function names(string $table, string $col, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $st = $this->pdo->prepare("SELECT id, $col FROM $table WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        return array_map('strval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** @param list<int> $ids @return array<int,string> */
    private function userNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $st = $this->pdo->prepare("SELECT id, TRIM(CONCAT(first_name, ' ', last_name)) FROM users WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute(array_values($ids));
        return array_map('strval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
