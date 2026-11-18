<?php
declare(strict_types=1);

namespace App\Services\Push;

use OpenSSLAsymmetricKey;
use RuntimeException;

/** Clés elliptiques P-256 (prime256v1) : conversions entre points/scalaires bruts et ressources OpenSSL. */
final class EcKeys
{
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    private const OID_P256 = '06082a8648ce3d030107';

    /** @return array{private:string,public:string} d (32 octets) et point non compressé (65 octets) */
    public static function generate(): array
    {
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($k === false) {
            throw new RuntimeException('Génération de clé EC impossible (OpenSSL).');
        }
        $d = openssl_pkey_get_details($k)['ec'];
        return ['private' => str_pad($d['d'], 32, "\0", STR_PAD_LEFT), 'public' => "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT)];
    }

    public static function publicFromPoint(string $point): OpenSSLAsymmetricKey
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('Point EC non compressé de 65 octets attendu.');
        }
        $der = hex2bin(self::SPKI_PREFIX) . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $k = openssl_pkey_get_public($pem);
        if ($k === false) {
            throw new RuntimeException('Point EC invalide (hors de la courbe).');
        }
        return $k;
    }

    public static function privateFromScalar(string $d, string $point): OpenSSLAsymmetricKey
    {
        if (strlen($d) !== 32 || strlen($point) !== 65) {
            throw new RuntimeException('Clé privée EC invalide.');
        }
        $oid = hex2bin(self::OID_P256);
        $der = self::seq(
            "\x02\x01\x01" . "\x04\x20" . $d . "\xa0" . chr(strlen($oid)) . $oid . "\xa1" . self::len(68) . "\x03" . self::len(66) . "\x00" . $point
        );
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        $k = openssl_pkey_get_private($pem);
        if ($k === false) {
            throw new RuntimeException('Clé privée EC illisible.');
        }
        return $k;
    }

    /** Secret ECDH partagé (32 octets). */
    public static function ecdh(OpenSSLAsymmetricKey $private, OpenSSLAsymmetricKey $public): string
    {
        $s = openssl_pkey_derive($public, $private, 32);
        if ($s === false) {
            throw new RuntimeException('Calcul ECDH impossible.');
        }
        return str_pad($s, 32, "\0", STR_PAD_LEFT);
    }

    /** Signature ECDSA P-256/SHA-256 au format brut r||s (64 octets), comme l'exige JWS ES256. */
    public static function signEs256(string $data, OpenSSLAsymmetricKey $private): string
    {
        if (!openssl_sign($data, $der, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature impossible.');
        }
        return self::derToRaw($der);
    }

    public static function verifyEs256(string $data, string $raw, OpenSSLAsymmetricKey $public): bool
    {
        return strlen($raw) === 64 && openssl_verify($data, self::rawToDer($raw), $public, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function derToRaw(string $der): string
    {
        $o = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0);
        $rl = ord($der[$o + 1]);
        $r = substr($der, $o + 2, $rl);
        $sl = ord($der[$o + 2 + $rl + 1]);
        $s = substr($der, $o + 2 + $rl + 2, $sl);
        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    private static function rawToDer(string $raw): string
    {
        $int = static function (string $v): string {
            $v = ltrim($v, "\0");
            if ($v === '' || ord($v[0]) & 0x80) {
                $v = "\0" . $v;
            }
            return "\x02" . chr(strlen($v)) . $v;
        };
        return self::seq($int(substr($raw, 0, 32)) . $int(substr($raw, 32)));
    }

    private static function seq(string $body): string
    {
        return "\x30" . self::len(strlen($body)) . $body;
    }

    private static function len(int $n): string
    {
        return $n < 128 ? chr($n) : ($n < 256 ? "\x81" . chr($n) : "\x82" . pack('n', $n));
    }
}
