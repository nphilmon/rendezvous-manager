<?php
declare(strict_types=1);

namespace App\Validation;

/** Validation des préférences d'affichage (utilisateur ou organisation). Seules les clés fournies sont contrôlées et renvoyées. */
final class UiSettings
{
    public const THEMES = ['auto', 'light', 'dark'];
    public const VIEWS = ['day', 'week', 'month', 'agenda'];
    public const STEPS = [5, 10, 15, 30, 60];

    /**
     * @param array<string,mixed> $in valeurs reçues ; $current : réglages effectifs actuels (pour contrôler début < fin)
     * @param array<string,mixed> $current
     * @param callable(int):bool|null $calendarOk true si le calendrier existe et permet la création (null : réglage refusé, ex. réglages d'organisation)
     * @param array<string,list<string>> $e erreurs par champ (par référence)
     * @return array<string,mixed> valeurs valides
     */
    public static function validate(array $in, array $current, ?callable $calendarOk, array &$e): array
    {
        $out = [];
        $unknown = array_diff(array_keys($in), array_keys(\App\Repositories\SettingsRepository::UI_DEFAULTS));
        foreach ($unknown as $k) {
            $e[(string) $k][] = 'Réglage inconnu.';
        }
        $enum = static function (string $k, array $allowed) use ($in, &$out, &$e): void {
            if (array_key_exists($k, $in)) {
                is_string($in[$k]) && in_array($in[$k], $allowed, true) ? $out[$k] = $in[$k] : $e[$k][] = 'Valeur attendue : ' . implode(', ', $allowed) . '.';
            }
        };
        $int = static function (string $k, int $min, int $max) use ($in, &$out, &$e): void {
            if (array_key_exists($k, $in)) {
                is_int($in[$k]) && $in[$k] >= $min && $in[$k] <= $max ? $out[$k] = $in[$k] : $e[$k][] = "Entier entre $min et $max attendu.";
            }
        };
        $bool = static function (string $k) use ($in, &$out, &$e): void {
            if (array_key_exists($k, $in)) {
                is_bool($in[$k]) ? $out[$k] = $in[$k] : $e[$k][] = 'Booléen attendu.';
            }
        };
        $enum('theme', self::THEMES);
        $enum('view', self::VIEWS);
        $int('week_start', 0, 1);
        if (isset($out['week_start']) && !in_array($out['week_start'], [0, 1], true)) {
            unset($out['week_start']);
        }
        $int('start_hour', 0, 23);
        $int('end_hour', 1, 24);
        if (array_key_exists('step', $in)) {
            is_int($in['step']) && in_array($in['step'], self::STEPS, true) ? $out['step'] = $in['step'] : $e['step'][] = 'Pas attendu : ' . implode(', ', self::STEPS) . ' minutes.';
        }
        $bool('weekend');
        $bool('hour12');
        $int('default_duration', 5, 720);
        if (array_key_exists('default_calendar', $in)) {
            $v = $in['default_calendar'];
            if ($v === null || $v === '') {
                $out['default_calendar'] = null;
            } elseif ($calendarOk === null) {
                $e['default_calendar'][] = 'Réglage personnel : non applicable à l\'organisation.';
            } elseif (is_int($v) && $v > 0 && $calendarOk($v)) {
                $out['default_calendar'] = $v;
            } else {
                $e['default_calendar'][] = 'Calendrier introuvable ou sans droit de création.';
            }
        }
        if (array_key_exists('hidden_calendars', $in)) {
            $v = $in['hidden_calendars'];
            if ($calendarOk === null) {
                $e['hidden_calendars'][] = 'Réglage personnel : non applicable à l\'organisation.';
            } elseif (!is_array($v) || !array_is_list($v) || count($v) > 200 || array_filter($v, static fn ($x) => !is_int($x) || $x < 1) !== []) {
                $e['hidden_calendars'][] = 'Liste de 200 identifiants de calendrier au plus attendue.';
            } else {
                $out['hidden_calendars'] = array_values(array_unique($v));
            }
        }
        $merged = $out + $current;
        if (($merged['end_hour'] ?? 24) <= ($merged['start_hour'] ?? 0)) {
            $e['end_hour'][] = 'La fin de la grille doit être postérieure au début.';
        }
        return $out;
    }
}
