<?php
declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;
use DateTimeImmutable;
use DateTimeZone;

/** Validation serveur des rendez-vous. La validation JavaScript n'est jamais considérée comme suffisante. */
final class AppointmentValidator
{
    public const STATUSES = ['scheduled', 'confirmed', 'tentative', 'cancelled', 'completed'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const MAX_REMINDER_MINUTES = 40320; // 4 semaines

    /**
     * @param array<string,mixed> $in données complètes (fusionnées avec l'existant pour une mise à jour)
     * @return array<string,mixed> colonnes normalisées
     * @throws HttpException 422 avec details[champ] = messages
     */
    public static function validate(array $in, string $defaultTimezone): array
    {
        $e = [];
        $err = static function (string $f, string $m) use (&$e): void {
            $e[$f][] = $m;
        };
        $str = static function (string $k, int $max, bool $required = false) use ($in, $err): ?string {
            $v = $in[$k] ?? null;
            if ($v === null || $v === '') {
                if ($required) {
                    $err($k, 'Champ requis.');
                }
                return null;
            }
            if (!is_string($v)) {
                $err($k, 'Texte attendu.');
                return null;
            }
            $v = trim($v);
            if ($required && $v === '') {
                $err($k, 'Champ requis.');
            }
            if (mb_strlen($v) > $max) {
                $err($k, "Maximum $max caractères.");
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)) {
                $err($k, 'Caractères de contrôle interdits.');
            }
            return $v === '' ? null : $v;
        };

        $title = $str('title', 200, true);
        $description = $str('description', 10000);
        $location = $str('location', 190);
        $address = $str('address', 255);
        $notes = $str('notes', 10000);

        $calendarId = self::positiveInt($in['calendar_id'] ?? null);
        if ($calendarId === null) {
            $err('calendar_id', 'Calendrier requis.');
        }
        $categoryId = ($in['category_id'] ?? null) === null || ($in['category_id'] ?? '') === '' ? null : self::positiveInt($in['category_id']);
        if (($in['category_id'] ?? null) !== null && ($in['category_id'] ?? '') !== '' && $categoryId === null) {
            $err('category_id', 'Catégorie invalide.');
        }
        $contactId = ($in['contact_id'] ?? null) === null || ($in['contact_id'] ?? '') === '' ? null : self::positiveInt($in['contact_id']);
        if (($in['contact_id'] ?? null) !== null && ($in['contact_id'] ?? '') !== '' && $contactId === null) {
            $err('contact_id', 'Contact invalide.');
        }

        $status = $in['status'] ?? 'scheduled';
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            $err('status', 'Statut invalide.');
        }
        $priority = $in['priority'] ?? 'normal';
        if (!is_string($priority) || !in_array($priority, self::PRIORITIES, true)) {
            $err('priority', 'Priorité invalide.');
        }
        $color = $in['color'] ?? null;
        if ($color === '') {
            $color = null;
        }
        if ($color !== null && (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color))) {
            $err('color', 'Couleur invalide (#RRGGBB).');
        }
        $tz = $in['timezone'] ?? $defaultTimezone;
        if (!is_string($tz) || !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $err('timezone', 'Fuseau horaire invalide.');
            $tz = $defaultTimezone;
        }

        $reminder = $in['reminder_minutes'] ?? null;
        if ($reminder === '') {
            $reminder = null;
        }
        if ($reminder !== null) {
            if (!is_int($reminder) && !(is_string($reminder) && ctype_digit($reminder))) {
                $err('reminder_minutes', 'Rappel invalide.');
                $reminder = null;
            } else {
                $reminder = (int) $reminder;
                if ($reminder > self::MAX_REMINDER_MINUTES) {
                    $err('reminder_minutes', 'Rappel : 4 semaines maximum.');
                }
            }
        }

        $allDay = $in['all_day'] ?? false;
        if (!is_bool($allDay) && !in_array($allDay, [0, 1, '0', '1'], true)) {
            $err('all_day', 'Booléen attendu.');
        }
        $allDay = $allDay === true || $allDay === 1 || $allDay === '1';
        [$start, $end] = self::times($in['start'] ?? null, $in['end'] ?? null, $allDay, $err);

        if ($e) {
            throw new HttpException(422, 'Données invalides.', $e);
        }
        return [
            'calendar_id' => $calendarId, 'category_id' => $categoryId, 'contact_id' => $contactId,
            'title' => $title, 'description' => $description, 'location' => $location, 'address' => $address, 'notes' => $notes,
            'start_utc' => $start, 'end_utc' => $end, 'all_day' => $allDay ? 1 : 0, 'timezone' => $tz,
            'status' => $status, 'priority' => $priority, 'color' => $color === null ? null : strtolower($color),
            'reminder_minutes' => $reminder,
        ];
    }

    /** @return array{0:?string,1:?string} dates SQL UTC */
    private static function times(mixed $s, mixed $e, bool $allDay, callable $err): array
    {
        $parse = static function (mixed $v, string $f) use ($allDay, $err): ?DateTimeImmutable {
            if (!is_string($v)) {
                $err($f, 'Date requise.');
                return null;
            }
            if ($allDay) {
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(T00:00(:00)?(\.0+)?Z)?$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    $err($f, 'Date AAAA-MM-JJ attendue.');
                    return null;
                }
                return new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} 00:00:00", new DateTimeZone('UTC'));
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', $v)) {
                $err($f, 'Date et heure ISO 8601 avec fuseau (Z ou ±hh:mm) attendues.');
                return null;
            }
            try {
                return (new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'));
            } catch (\Exception) {
                $err($f, 'Date invalide.');
                return null;
            }
        };
        $a = $parse($s, 'start');
        $b = $parse($e, 'end');
        if ($a && $b && $b <= $a) {
            $err('end', $allDay ? 'La fin (exclusive) doit être postérieure au début.' : 'La fin doit être postérieure au début.');
        }
        if ($a && $b && ($b->getTimestamp() - $a->getTimestamp()) > 366 * 86400) {
            $err('end', 'Durée maximale : 366 jours.');
        }
        $fmt = static fn (?DateTimeImmutable $d): ?string => $d?->format('Y-m-d H:i:s');
        return [$fmt($a), $fmt($b)];
    }

    private static function positiveInt(mixed $v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        return is_string($v) && ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
    }
}
