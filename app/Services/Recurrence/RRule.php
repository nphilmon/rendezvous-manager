<?php
declare(strict_types=1);

namespace App\Services\Recurrence;

use App\Core\HttpException;
use DateTimeImmutable;
use DateTimeZone;
use Generator;

/**
 * Sous-ensemble d'iCalendar RRULE (RFC 5545) : FREQ=DAILY|WEEKLY|MONTHLY|YEARLY, INTERVAL, BYDAY (avec rang pour
 * MONTHLY/YEARLY), BYMONTHDAY (négatif accepté), BYMONTH, COUNT, UNTIL, WKST (MO ou SU).
 * Les occurrences sont calculées à l'heure locale du fuseau de la série : l'heure « murale » est conservée à travers
 * les changements d'heure. Une heure inexistante (saut de printemps) est décalée vers l'avant ; une heure répétée
 * (retour d'automne) prend la première occurrence. Aucune ligne n'est stockée par occurrence.
 */
final class RRule
{
    public const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    public const FREQS = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    public const MAX_COUNT = 5000;
    private const MAX_PERIODS = 20000;
    private const MAX_EMPTY_PERIODS = 600;

    /**
     * @return array{freq:string,interval:int,byday:list<array{n:?int,d:int}>,bymonthday:list<int>,bymonth:list<int>,count:?int,until:?string,wkst:int}
     * @throws HttpException 422 si la règle est invalide ou hors du sous-ensemble pris en charge
     */
    public static function parse(string $s): array
    {
        $r = ['freq' => '', 'interval' => 1, 'byday' => [], 'bymonthday' => [], 'bymonth' => [], 'count' => null, 'until' => null, 'wkst' => 1];
        $err = static function (string $m): never {
            throw new HttpException(422, 'Données invalides.', ['recurrence' => [$m]]);
        };
        if ($s === '' || strlen($s) > 500) {
            $err('Règle de récurrence vide ou trop longue.');
        }
        $seen = [];
        foreach (explode(';', $s) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2 || $kv[1] === '') {
                $err('Règle de récurrence mal formée.');
            }
            [$k, $v] = [strtoupper($kv[0]), strtoupper($kv[1])];
            if (isset($seen[$k])) {
                $err("Paramètre $k répété.");
            }
            $seen[$k] = true;
            switch ($k) {
                case 'FREQ':
                    in_array($v, self::FREQS, true) || $err('Fréquence non prise en charge (DAILY, WEEKLY, MONTHLY, YEARLY).');
                    $r['freq'] = $v;
                    break;
                case 'INTERVAL':
                    (ctype_digit($v) && (int) $v >= 1 && (int) $v <= 999) || $err('INTERVAL : entier de 1 à 999.');
                    $r['interval'] = (int) $v;
                    break;
                case 'COUNT':
                    (ctype_digit($v) && (int) $v >= 1 && (int) $v <= self::MAX_COUNT) || $err('COUNT : entier de 1 à ' . self::MAX_COUNT . '.');
                    $r['count'] = (int) $v;
                    break;
                case 'UNTIL':
                    if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z)?$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                        $err('UNTIL : AAAAMMJJ ou AAAAMMJJThhmmssZ.');
                    }
                    $r['until'] = isset($m[4]) ? "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}" : "{$m[1]}-{$m[2]}-{$m[3]} 23:59:59";
                    break;
                case 'BYDAY':
                    foreach (explode(',', $v) as $item) {
                        if (!preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $item, $m)) {
                            $err('BYDAY invalide.');
                        }
                        $n = $m[1] === '' ? null : (int) $m[1];
                        if ($n !== null && ($n === 0 || abs($n) > 5)) {
                            $err('BYDAY : rang entre 1 et 5 (ou -1 à -5).');
                        }
                        $r['byday'][] = ['n' => $n, 'd' => self::DAYS[$m[2]]];
                    }
                    break;
                case 'BYMONTHDAY':
                    foreach (explode(',', $v) as $item) {
                        (preg_match('/^[+-]?\d{1,2}$/', $item) && (int) $item !== 0 && abs((int) $item) <= 31) || $err('BYMONTHDAY : 1 à 31 ou -1 à -31.');
                        $r['bymonthday'][] = (int) $item;
                    }
                    break;
                case 'BYMONTH':
                    foreach (explode(',', $v) as $item) {
                        (ctype_digit($item) && (int) $item >= 1 && (int) $item <= 12) || $err('BYMONTH : 1 à 12.');
                        $r['bymonth'][] = (int) $item;
                    }
                    break;
                case 'WKST':
                    in_array($v, ['MO', 'SU'], true) || $err('WKST : MO ou SU.');
                    $r['wkst'] = self::DAYS[$v];
                    break;
                default:
                    $err("Paramètre $k non pris en charge.");
            }
        }
        $r['freq'] !== '' || $err('FREQ est obligatoire.');
        ($r['count'] === null || $r['until'] === null) || $err('COUNT et UNTIL sont exclusifs.');
        $f = $r['freq'];
        if ($r['byday'] && !in_array($f, ['WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            $err('BYDAY n\'est pas pris en charge avec cette fréquence.');
        }
        if ($f === 'WEEKLY' && array_filter($r['byday'], fn ($b) => $b['n'] !== null)) {
            $err('Un rang dans BYDAY n\'a de sens que pour MONTHLY ou YEARLY.');
        }
        if ($r['bymonthday'] && !in_array($f, ['MONTHLY', 'YEARLY'], true)) {
            $err('BYMONTHDAY n\'est pris en charge que pour MONTHLY ou YEARLY.');
        }
        if ($r['bymonth'] && $f !== 'YEARLY') {
            $err('BYMONTH n\'est pris en charge que pour YEARLY.');
        }
        $r['byday'] = array_values(array_unique($r['byday'], SORT_REGULAR));
        $r['bymonthday'] = array_values(array_unique($r['bymonthday']));
        $r['bymonth'] = array_values(array_unique($r['bymonth']));
        sort($r['bymonth']);
        return $r;
    }

    /** @param array<string,mixed> $r règle normalisée (voir parse) */
    public static function format(array $r): string
    {
        $dayCode = array_flip(self::DAYS);
        $p = ['FREQ=' . $r['freq']];
        if ($r['interval'] > 1) {
            $p[] = 'INTERVAL=' . $r['interval'];
        }
        if ($r['byday']) {
            $p[] = 'BYDAY=' . implode(',', array_map(fn ($b) => ($b['n'] ?? '') . $dayCode[$b['d']], $r['byday']));
        }
        if ($r['bymonthday']) {
            $p[] = 'BYMONTHDAY=' . implode(',', $r['bymonthday']);
        }
        if ($r['bymonth']) {
            $p[] = 'BYMONTH=' . implode(',', $r['bymonth']);
        }
        if ($r['wkst'] === 7) {
            $p[] = 'WKST=SU';
        }
        if ($r['count'] !== null) {
            $p[] = 'COUNT=' . $r['count'];
        }
        if ($r['until'] !== null) {
            $p[] = 'UNTIL=' . str_replace(['-', ' ', ':'], ['', 'T', ''], $r['until']) . 'Z';
        }
        return implode(';', $p);
    }

    /**
     * Construit la règle depuis la saisie structurée de l'API :
     * {freq: daily|weekly|monthly|yearly, interval, byday: ["MO","2TU"], bymonthday: [15], bymonth: [3],
     *  end: {type: never|count|until, count, until: "AAAA-MM-JJ"}}.
     * La date « jusqu'au » est inclusive, à la fin de la journée locale du fuseau de la série.
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function fromInput(array $in, DateTimeZone $tz): array
    {
        $bad = static function (string $m): never {
            throw new HttpException(422, 'Données invalides.', ['recurrence' => [$m]]);
        };
        $freq = strtoupper((string) ($in['freq'] ?? ''));
        $interval = $in['interval'] ?? 1;
        if (!(is_int($interval) || (is_string($interval) && ctype_digit($interval)))) {
            $bad('Intervalle invalide.');
        }
        $parts = ['FREQ=' . $freq];
        if ((int) $interval !== 1) {
            $parts[] = 'INTERVAL=' . (int) $interval;
        }
        foreach (['byday' => 'BYDAY', 'bymonthday' => 'BYMONTHDAY', 'bymonth' => 'BYMONTH'] as $k => $name) {
            if (!empty($in[$k])) {
                if (!is_array($in[$k])) {
                    $bad("$k : liste attendue.");
                }
                $parts[] = $name . '=' . implode(',', array_map('strval', $in[$k]));
            }
        }
        $end = $in['end'] ?? ['type' => 'never'];
        $type = is_array($end) ? ($end['type'] ?? 'never') : 'never';
        if ($type === 'count') {
            $parts[] = 'COUNT=' . (string) ($end['count'] ?? '');
        } elseif ($type === 'until') {
            $d = (string) ($end['until'] ?? '');
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $bad('Date de fin invalide (AAAA-MM-JJ).');
            }
            $utc = (new DateTimeImmutable("$d 23:59:59", $tz))->setTimezone(new DateTimeZone('UTC'));
            $parts[] = 'UNTIL=' . $utc->format('Ymd\THis\Z');
        } elseif ($type !== 'never') {
            $bad('Type de fin invalide (never, count, until).');
        }
        return self::parse(implode(';', $parts)); // un seul chemin de validation
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function toInput(array $r, DateTimeZone $tz): array
    {
        $dayCode = array_flip(self::DAYS);
        $end = ['type' => 'never'];
        if ($r['count'] !== null) {
            $end = ['type' => 'count', 'count' => $r['count']];
        } elseif ($r['until'] !== null) {
            $end = ['type' => 'until', 'until' => (new DateTimeImmutable($r['until'], new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d')];
        }
        return ['freq' => strtolower($r['freq']), 'interval' => $r['interval'],
            'byday' => array_map(fn ($b) => ($b['n'] ?? '') . $dayCode[$b['d']], $r['byday']),
            'bymonthday' => $r['bymonthday'], 'bymonth' => $r['bymonth'], 'end' => $end];
    }

    /**
     * Occurrences (début, heure locale) dans l'ordre chronologique, DTSTART compris. Générateur : s'arrête quand
     * l'appelant cesse de l'itérer (COUNT/UNTIL sont respectés). $notBefore permet de sauter les périodes anciennes
     * lorsqu'il n'y a pas de COUNT.
     * @param array<string,mixed> $r
     * @return Generator<int,DateTimeImmutable>
     */
    public static function iterate(array $r, DateTimeImmutable $dtstart, ?DateTimeImmutable $notBefore = null): Generator
    {
        $tz = $dtstart->getTimezone();
        $utc = new DateTimeZone('UTC');
        $until = $r['until'] !== null ? new DateTimeImmutable($r['until'], $utc) : null;
        $y0 = (int) $dtstart->format('Y');
        $m0 = (int) $dtstart->format('n');
        $d0 = (int) $dtstart->format('j');
        $time = $dtstart->format('H:i:s');
        $limit = $r['count'] ?? PHP_INT_MAX;
        $n = 0;

        if ($until !== null && $dtstart > $until) {
            return;
        }
        yield $dtstart;
        if (++$n >= $limit) {
            return;
        }

        $k = 0;
        if ($notBefore !== null && $r['count'] === null && $notBefore > $dtstart) {
            $days = (int) floor(($notBefore->getTimestamp() - $dtstart->getTimestamp()) / 86400);
            $months = ((int) $notBefore->format('Y') - $y0) * 12 + ((int) $notBefore->format('n') - $m0);
            $k = max(0, intdiv(match ($r['freq']) {
                'DAILY' => $days, 'WEEKLY' => intdiv($days, 7), 'MONTHLY' => $months, default => intdiv($months, 12),
            }, $r['interval']) - 1);
        }

        $empty = 0;
        for ($periods = 0; $periods < self::MAX_PERIODS; $periods++, $k++) {
            $yielded = false;
            foreach (self::periodDates($r, $k, $y0, $m0, $d0) as [$y, $m, $d]) {
                if ([$y, $m, $d] <= [$y0, $m0, $d0]) {
                    continue; // jamais avant ni égal à DTSTART (déjà produit)
                }
                $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d %s', $y, $m, $d, $time), $tz);
                if ($until !== null && $dt > $until) {
                    return;
                }
                yield $dt;
                $yielded = true;
                if (++$n >= $limit) {
                    return;
                }
            }
            $empty = $yielded ? 0 : $empty + 1;
            if ($empty > self::MAX_EMPTY_PERIODS) {
                return; // règle qui ne produit jamais de date (ex. 30 février)
            }
        }
    }

    /** @return list<array{0:int,1:int,2:int}> dates [a,m,j] de la période $k, triées */
    private static function periodDates(array $r, int $k, int $y0, int $m0, int $d0): array
    {
        $step = $k * $r['interval'];
        switch ($r['freq']) {
            case 'DAILY':
                return [self::addDays($y0, $m0, $d0, $step)];
            case 'WEEKLY':
                $wk = $r['wkst'];
                $dow0 = self::dow($y0, $m0, $d0);
                $start = self::addDays($y0, $m0, $d0, -(($dow0 - $wk + 7) % 7) + 7 * $step);
                $days = $r['byday'] ? array_map(fn ($b) => $b['d'], $r['byday']) : [$dow0];
                $offsets = array_unique(array_map(fn ($w) => ($w - $wk + 7) % 7, $days));
                sort($offsets);
                return array_map(fn ($o) => self::addDays($start[0], $start[1], $start[2], $o), $offsets);
            case 'MONTHLY':
                $t = $y0 * 12 + ($m0 - 1) + $step;
                return self::monthDates($r, intdiv($t, 12), $t % 12 + 1, $d0);
            default: // YEARLY
                $y = $y0 + $step;
                $out = [];
                foreach ($r['bymonth'] ?: [$m0] as $m) {
                    array_push($out, ...self::monthDates($r, $y, $m, $d0));
                }
                usort($out, fn ($a, $b) => $a <=> $b);
                return $out;
        }
    }

    /** @return list<array{0:int,1:int,2:int}> */
    private static function monthDates(array $r, int $y, int $m, int $d0): array
    {
        $dim = (int) gmdate('t', gmmktime(0, 0, 0, $m, 1, $y));
        $days = [];
        if ($r['byday']) {
            foreach ($r['byday'] as $b) {
                $all = [];
                for ($d = 1; $d <= $dim; $d++) {
                    if (self::dow($y, $m, $d) === $b['d']) {
                        $all[] = $d;
                    }
                }
                if ($b['n'] === null) {
                    array_push($days, ...$all);
                } else {
                    $i = $b['n'] > 0 ? $b['n'] - 1 : count($all) + $b['n'];
                    if (isset($all[$i])) {
                        $days[] = $all[$i];
                    }
                }
            }
            if ($r['bymonthday']) { // BYMONTHDAY + BYDAY : intersection
                $md = array_map(fn ($x) => $x > 0 ? $x : $dim + $x + 1, $r['bymonthday']);
                $days = array_values(array_filter($days, fn ($d) => in_array($d, $md, true)));
            }
        } elseif ($r['bymonthday']) {
            foreach ($r['bymonthday'] as $x) {
                $d = $x > 0 ? $x : $dim + $x + 1;
                if ($d >= 1 && $d <= $dim) {
                    $days[] = $d;
                }
            }
        } elseif ($d0 <= $dim) {
            $days[] = $d0; // le 31 d'un mois de 30 jours est ignoré (RFC 5545)
        }
        $days = array_values(array_unique($days));
        sort($days);
        return array_map(fn ($d) => [$y, $m, $d], $days);
    }

    private static function dow(int $y, int $m, int $d): int
    {
        return (int) gmdate('N', gmmktime(0, 0, 0, $m, $d, $y));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function addDays(int $y, int $m, int $d, int $n): array
    {
        $t = gmmktime(0, 0, 0, $m, $d + $n, $y);
        return [(int) gmdate('Y', $t), (int) gmdate('n', $t), (int) gmdate('j', $t)];
    }
}
