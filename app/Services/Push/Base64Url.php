<?php
declare(strict_types=1);

namespace App\Services\Push;

final class Base64Url
{
    public static function encode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** @return string|null null si la chaîne n'est pas du base64url valide */
    public static function decode(string $s): ?string
    {
        if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $s)) {
            return null;
        }
        $s = rtrim($s, '=');
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $r === false ? null : $r;
    }
}
