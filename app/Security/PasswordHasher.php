<?php
declare(strict_types=1);

namespace App\Security;

final class PasswordHasher
{
    public static function hash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algo);
    }

    /** Hash factice : égalise le temps de réponse quand l'identifiant n'existe pas. */
    public static function dummyHash(): string
    {
        static $h = null;
        return $h ??= self::hash('dummy-password-for-timing');
    }

    /** @return list<string> messages d'erreur (vide = valide) */
    public static function validateStrength(string $password, int $min = 12, string $username = ''): array
    {
        $e = [];
        if (mb_strlen($password) < $min) {
            $e[] = "Au moins $min caractères.";
        }
        if (strlen($password) > 1024) {
            $e[] = 'Mot de passe trop long.';
        }
        if (!preg_match('/\p{Ll}/u', $password) || !preg_match('/\p{Lu}/u', $password) || !preg_match('/\d/', $password)) {
            $e[] = 'Doit contenir une minuscule, une majuscule et un chiffre.';
        }
        if ($username !== '' && stripos($password, $username) !== false) {
            $e[] = "Ne doit pas contenir l'identifiant.";
        }
        return $e;
    }
}
