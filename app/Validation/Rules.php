<?php
declare(strict_types=1);

namespace App\Validation;

use App\Core\HttpException;

/** Règles de validation partagées ; les erreurs sont accumulées par champ puis levées en HTTP 422. */
final class Rules
{
    /** @param array<string,mixed> $in @param array<string,list<string>> $e */
    public static function text(array $in, string $k, int $max, bool $required, array &$e): ?string
    {
        $v = $in[$k] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $e[$k][] = 'Champ requis.';
            }
            return null;
        }
        if (!is_string($v)) {
            $e[$k][] = 'Texte attendu.';
            return null;
        }
        $v = trim($v);
        if ($v === '') {
            if ($required) {
                $e[$k][] = 'Champ requis.';
            }
            return null;
        }
        if (mb_strlen($v) > $max) {
            $e[$k][] = "Maximum $max caractères.";
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)) {
            $e[$k][] = 'Caractères de contrôle interdits.';
        }
        return $v;
    }

    /** @param array<string,mixed> $in @param array<string,list<string>> $e */
    public static function color(array $in, string $k, bool $required, array &$e): ?string
    {
        $v = $in[$k] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                $e[$k][] = 'Couleur requise.';
            }
            return null;
        }
        if (!is_string($v) || !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            $e[$k][] = 'Couleur invalide (#RRGGBB).';
            return null;
        }
        return strtolower($v);
    }

    /** @param array<string,mixed> $in @param array<string,list<string>> $e */
    public static function email(array $in, string $k, array &$e): ?string
    {
        $v = self::text($in, $k, 190, false, $e);
        if ($v !== null && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $e[$k][] = 'Adresse e-mail invalide.';
        }
        return $v;
    }

    /** @param array<string,mixed> $in @param array<string,list<string>> $e */
    public static function phone(array $in, string $k, array &$e): ?string
    {
        $v = self::text($in, $k, 40, false, $e);
        if ($v !== null && !preg_match('/^[0-9+().\s\-]{3,40}$/', $v)) {
            $e[$k][] = 'Numéro de téléphone invalide.';
        }
        return $v;
    }

    /** @param array<string,mixed> $in @param list<string> $allowed @param array<string,list<string>> $e */
    public static function enum(array $in, string $k, array $allowed, string $default, array &$e): string
    {
        $v = $in[$k] ?? $default;
        if (!is_string($v) || !in_array($v, $allowed, true)) {
            $e[$k][] = 'Valeur invalide.';
            return $default;
        }
        return $v;
    }

    /** @param array<string,list<string>> $e */
    public static function throwIf(array $e): void
    {
        if ($e) {
            throw new HttpException(422, 'Données invalides.', $e);
        }
    }

    public static function id(mixed $v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        return is_string($v) && ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
    }
}
