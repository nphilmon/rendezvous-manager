<?php
declare(strict_types=1);

namespace App\Services\Transfer;

use DateTimeImmutable;
use DateTimeZone;

/** iCalendar (RFC 5545) : écriture et lecture. Le texte est échappé (\\ ; , retour à la ligne) et les lignes sont repliées à 75 octets : aucune injection de propriété possible. */
final class Ics
{
    private const STATUS = ['confirmed' => 'CONFIRMED', 'tentative' => 'TENTATIVE', 'cancelled' => 'CANCELLED', 'scheduled' => 'CONFIRMED', 'completed' => 'CONFIRMED'];
    private const PRIORITY = ['urgent' => 1, 'high' => 3, 'normal' => 5, 'low' => 9];

    public static function escape(string $s): string
    {
        return str_replace(["\\", ";", ",", "\r\n", "\r", "\n"], ["\\\\", "\;", "\\,", "\\n", "\\n", "\\n"], $s);
    }

    public static function unescape(string $s): string
    {
        return preg_replace_callback('/\\\\([nN;,\\\\])/', static fn ($m) => strtolower($m[1]) === 'n' ? "\n" : $m[1], $s) ?? $s;
    }

    public static function fold(string $line): string
    {
        $out = '';
        $len = 0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $b = strlen($ch);
            if ($len + $b > 75) {
                $out .= "\r\n ";
                $len = 1;
            }
            $out .= $ch;
            $len += $b;
        }
        return $out . "\r\n";
    }

    /**
     * @param list<array{row:array<string,mixed>,exceptions:list<array<string,mixed>>}> $items rendez-vous maîtres (séries : avec exceptions)
     */
    public static function write(array $items, string $calendarName, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $out = '';
        $add = static function (string $l) use (&$out): void {
            $out .= self::fold($l);
        };
        $add('BEGIN:VCALENDAR');
        $add('VERSION:2.0');
        $add('PRODID:-//RendezVous Manager//FR');
        $add('CALSCALE:GREGORIAN');
        $add('X-WR-CALNAME:' . self::escape($calendarName));
        $zones = [];
        $events = '';
        foreach ($items as $it) {
            $r = $it['row'];
            $recurring = !empty($r['rrule']);
            $tz = $r['all_day'] ? 'UTC' : (($recurring ? ($r['rec_tz'] ?? null) : null) ?: $r['timezone']);
            if ($recurring && !$r['all_day']) {
                $zones[$tz] = min($zones[$tz] ?? PHP_INT_MAX, strtotime($r['start_utc'] . ' UTC'));
            }
            $events .= self::event($r, $tz, $now, null, array_column($it['exceptions'], 'original_start_utc'));
            foreach ($it['exceptions'] as $x) {
                if ($x['exception_type'] === 'modified' && $x['new_start_utc'] !== null) {
                    $ov = array_replace($r, json_decode((string) ($x['overrides'] ?? '{}'), true) ?: [], ['start_utc' => $x['new_start_utc'], 'end_utc' => $x['new_end_utc'], 'rrule' => null]);
                    $events .= self::event($ov, $tz, $now, $x['original_start_utc']);
                }
            }
        }
        foreach ($zones as $name => $since) {
            $out .= self::timezone($name, $since);
        }
        return $out . $events . self::fold('END:VCALENDAR');
    }

    /** @param array<string,mixed> $r */
    /** @param list<string> $exdates débuts d'origine (UTC) des occurrences annulées ou déplacées */
    private static function event(array $r, string $tz, DateTimeImmutable $now, ?string $recurrenceId, array $exdates = []): string
    {
        $recurring = !empty($r['rrule']);
        $L = [];
        $L[] = 'BEGIN:VEVENT';
        $L[] = 'UID:' . $r['uid'];
        $L[] = 'DTSTAMP:' . $now->format('Ymd\THis\Z');
        $dt = static function (string $utc) use ($r, $tz, $recurring): string {
            $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
            if ($r['all_day']) {
                return ';VALUE=DATE:' . $d->format('Ymd');
            }
            return $recurring ? ';TZID=' . $tz . ':' . $d->setTimezone(new DateTimeZone($tz))->format('Ymd\THis') : ':' . $d->format('Ymd\THis\Z');
        };
        if ($recurrenceId !== null) {
            $L[] = 'RECURRENCE-ID' . $dt($recurrenceId);
        }
        $L[] = 'DTSTART' . $dt($r['start_utc']);
        $L[] = 'DTEND' . $dt($r['end_utc']);
        $L[] = 'SUMMARY:' . self::escape((string) $r['title']);
        if (($r['description'] ?? '') !== '' && $r['description'] !== null) {
            $L[] = 'DESCRIPTION:' . self::escape((string) $r['description']);
        }
        $loc = trim(implode(', ', array_filter([(string) ($r['location'] ?? ''), (string) ($r['address'] ?? '')], static fn ($v) => $v !== '')));
        if ($loc !== '') {
            $L[] = 'LOCATION:' . self::escape($loc);
        }
        $L[] = 'STATUS:' . self::STATUS[$r['status']];
        $L[] = 'X-RVM-STATUS:' . $r['status'];
        $L[] = 'PRIORITY:' . self::PRIORITY[$r['priority']];
        if (!empty($r['category_name'])) {
            $L[] = 'CATEGORIES:' . self::escape((string) $r['category_name']);
        }
        if ($recurring) {
            $L[] = 'RRULE:' . $r['rrule'];
            foreach ($exdates as $x) {
                $L[] = 'EXDATE' . $dt($x);
            }
        }
        $L[] = 'END:VEVENT';
        $s = '';
        foreach ($L as $l) {
            $s .= self::fold($l);
        }
        return $s;
    }

    /** VTIMEZONE sans bibliothèque : transitions explicites (STANDARD/DAYLIGHT) sur 12 ans à partir du début de la série. */
    private static function timezone(string $name, int $since): string
    {
        try {
            $tz = new DateTimeZone($name);
        } catch (\Exception) {
            return '';
        }
        $from = $since - 366 * 86400;
        $to = $since + 12 * 366 * 86400;
        $tr = $tz->getTransitions($from, $to);
        $s = self::fold('BEGIN:VTIMEZONE') . self::fold('TZID:' . $name);
        $prev = $tr[0]['offset'] ?? 0;
        $fmt = static fn (int $o): string => sprintf('%s%02d%02d', $o < 0 ? '-' : '+', intdiv(abs($o), 3600), intdiv(abs($o) % 3600, 60));
        foreach (array_slice($tr, 1) as $t) {
            $local = (new DateTimeImmutable('@' . ($t['ts'] + $prev)))->format('Ymd\THis');
            $s .= self::fold($t['isdst'] ? 'BEGIN:DAYLIGHT' : 'BEGIN:STANDARD') . self::fold('DTSTART:' . $local) . self::fold('TZOFFSETFROM:' . $fmt($prev))
                . self::fold('TZOFFSETTO:' . $fmt($t['offset'])) . self::fold('TZNAME:' . $t['abbr']) . self::fold($t['isdst'] ? 'END:DAYLIGHT' : 'END:STANDARD');
            $prev = $t['offset'];
        }
        if (count($tr) <= 1) { // fuseau sans changement d'heure
            $o = $tr[0]['offset'] ?? 0;
            $s .= self::fold('BEGIN:STANDARD') . self::fold('DTSTART:19700101T000000') . self::fold('TZOFFSETFROM:' . $fmt($o)) . self::fold('TZOFFSETTO:' . $fmt($o)) . self::fold('END:STANDARD');
        }
        return $s . self::fold('END:VTIMEZONE');
    }

    /**
     * Lecture : renvoie les VEVENT sous forme de listes de propriétés.
     * @return list<array<string,array{params:array<string,string>,value:string}>> une valeur par propriété (la dernière gagne) ; EXDATE ignoré ici
     */
    public static function parse(string $s): array
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace("/\n[ \t]/", '', $s) ?? $s; // dépliage
        $events = [];
        $cur = null;
        $depth = 0;
        foreach (explode("\n", $s) as $line) {
            if ($line === '') {
                continue;
            }
            $u = strtoupper(trim($line));
            if ($u === 'BEGIN:VEVENT') {
                $cur = [];
                $depth = 0;
                continue;
            }
            if ($u === 'END:VEVENT') {
                if ($cur !== null) {
                    $events[] = $cur;
                }
                $cur = null;
                continue;
            }
            if ($cur === null) {
                continue;
            }
            if (str_starts_with($u, 'BEGIN:')) { // VALARM imbriqué : ses propriétés ne sont pas celles de l'événement
                $depth++;
                continue;
            }
            if (str_starts_with($u, 'END:')) {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth > 0 || !str_contains($line, ':')) {
                continue;
            }
            [$left, $value] = explode(':', $line, 2);
            $parts = explode(';', $left);
            $name = strtoupper(array_shift($parts));
            $params = [];
            foreach ($parts as $p) {
                if (str_contains($p, '=')) {
                    [$k, $v] = explode('=', $p, 2);
                    $params[strtoupper($k)] = trim($v, '"');
                }
            }
            if ($name === 'EXDATE') {
                $cur['EXDATE'] = ['params' => $params, 'value' => (isset($cur['EXDATE']) ? $cur['EXDATE']['value'] . ',' : '') . $value];
                continue;
            }
            $cur[$name] = ['params' => $params, 'value' => $value];
        }
        return $events;
    }
}
