<?php
declare(strict_types=1);

namespace App\Services\Recurrence;

use DateTimeImmutable;
use DateTimeZone;

/** Déplie une série en occurrences à la demande (jamais stockées), en appliquant les exceptions. */
final class RecurrenceService
{
    public const OVERRIDABLE = ['title', 'description', 'location', 'address', 'status', 'priority', 'color', 'notes'];
    private const FMT = 'Y-m-d H:i:s';

    /**
     * @param array<string,mixed> $row ligne appointments + rrule, dtstart_local, rec_tz
     * @return array{rule:array<string,mixed>,dtstart:DateTimeImmutable,seconds:int,allDay:bool,tz:DateTimeZone}
     */
    public static function context(array $row): array
    {
        $allDay = (bool) $row['all_day'];
        $utc = new DateTimeZone('UTC');
        $tz = $allDay ? $utc : new DateTimeZone($row['rec_tz'] ?: $row['timezone']);
        $dtstart = new DateTimeImmutable($allDay ? substr($row['start_utc'], 0, 10) . ' 00:00:00' : $row['dtstart_local'], $tz);
        $seconds = (int) (strtotime($row['end_utc'] . ' UTC') - strtotime($row['start_utc'] . ' UTC'));
        return ['rule' => RRule::parse($row['rrule']), 'dtstart' => $dtstart, 'seconds' => $seconds, 'allDay' => $allDay, 'tz' => $tz];
    }

    /**
     * Occurrences chevauchant [from, to), triées. Une occurrence déplacée est comptée à sa nouvelle position.
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $exceptions clé : début d'origine « Y-m-d H:i:s » (UTC)
     * @return list<array{original:string,start:string,end:string,overrides:array<string,mixed>,exception:bool}>
     */
    public static function expand(array $row, array $exceptions, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 1000): array
    {
        $c = self::context($row);
        $out = [];
        $nb = $from->modify('-' . ($c['seconds'] + 2 * 86400) . ' seconds')->setTimezone($c['tz']);
        foreach (RRule::iterate($c['rule'], $c['dtstart'], $nb) as $dt) {
            [$s, $e] = self::bounds($dt, $c);
            if ($s >= $to) {
                break;
            }
            if ($e <= $from) {
                continue;
            }
            if (isset($exceptions[$s->format(self::FMT)])) {
                continue; // traitée ci-dessous avec sa position effective
            }
            $out[] = ['original' => $s->format(self::FMT), 'start' => $s->format(self::FMT), 'end' => $e->format(self::FMT), 'overrides' => [], 'exception' => false];
            if (count($out) >= $limit) {
                break;
            }
        }
        foreach ($exceptions as $orig => $x) {
            if ($x['exception_type'] !== 'modified') {
                continue;
            }
            $o = self::applyException($orig, $c, $x);
            if ($o !== null && strtotime($o['start'] . ' UTC') < $to->getTimestamp() && strtotime($o['end'] . ' UTC') > $from->getTimestamp()) {
                $out[] = $o;
            }
        }
        usort($out, fn ($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
        return array_slice($out, 0, $limit);
    }

    /**
     * Occurrence d'origine donnée (avec exception éventuelle) ; null si elle n'existe pas dans la série ou est annulée.
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $exceptions
     * @return array{original:string,start:string,end:string,overrides:array<string,mixed>,exception:bool}|null
     */
    public static function find(array $row, array $exceptions, string $original): ?array
    {
        $c = self::context($row);
        $target = new DateTimeImmutable($original, new DateTimeZone('UTC'));
        foreach (RRule::iterate($c['rule'], $c['dtstart'], $target->modify('-3 days')->setTimezone($c['tz'])) as $dt) {
            [$s, $e] = self::bounds($dt, $c);
            if ($s > $target) {
                return null;
            }
            if ($s == $target) {
                if (isset($exceptions[$original])) {
                    return self::applyException($original, $c, $exceptions[$original]);
                }
                return ['original' => $original, 'start' => $s->format(self::FMT), 'end' => $e->format(self::FMT), 'overrides' => [], 'exception' => false];
            }
        }
        return null;
    }

    /** Nombre d'occurrences de la règle strictement avant $original (exceptions ignorées). @param array<string,mixed> $row */
    public static function countBefore(array $row, string $original): int
    {
        $c = self::context($row);
        $target = new DateTimeImmutable($original, new DateTimeZone('UTC'));
        $n = 0;
        foreach (RRule::iterate($c['rule'], $c['dtstart']) as $dt) {
            if (self::bounds($dt, $c)[0] >= $target) {
                break;
            }
            $n++;
        }
        return $n;
    }

    /** @param array<string,mixed> $c @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
    private static function bounds(DateTimeImmutable $dt, array $c): array
    {
        $s = $dt->setTimezone(new DateTimeZone('UTC'));
        return [$s, $s->modify('+' . $c['seconds'] . ' seconds')];
    }

    /** @param array<string,mixed> $c @param array<string,mixed> $x @return array{original:string,start:string,end:string,overrides:array<string,mixed>,exception:bool}|null */
    private static function applyException(string $orig, array $c, array $x): ?array
    {
        if ($x['exception_type'] === 'cancelled') {
            return null;
        }
        $ov = is_string($x['overrides'] ?? null) ? (json_decode($x['overrides'], true) ?: []) : ($x['overrides'] ?? []);
        $start = $x['new_start_utc'] ?? $orig;
        $end = $x['new_end_utc'] ?? gmdate(self::FMT, strtotime($start . ' UTC') + $c['seconds']);
        return ['original' => $orig, 'start' => $start, 'end' => $end, 'overrides' => array_intersect_key($ov, array_flip(self::OVERRIDABLE)), 'exception' => true];
    }
}
