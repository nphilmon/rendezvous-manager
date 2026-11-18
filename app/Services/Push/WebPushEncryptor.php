<?php
declare(strict_types=1);

namespace App\Services\Push;

use RuntimeException;

/** Chiffrement de la charge utile Web Push (RFC 8291, codage de contenu aes128gcm de la RFC 8188). */
final class WebPushEncryptor
{
    public const RECORD_SIZE = 4096;
    public const MAX_PAYLOAD = 3993; // 4096 − 86 (en-tête) − 17 (délimiteur + balise)

    /**
     * @param string $payload texte clair (≤ 3993 octets)
     * @param string $uaPublic point P-256 non compressé du navigateur (p256dh, 65 octets)
     * @param string $authSecret secret d'authentification du navigateur (16 octets)
     * @param array{private:string,public:string}|null $asKey clé éphémère du serveur (aléatoire si null ; fixée dans les tests)
     * @param string|null $salt sel de 16 octets (aléatoire si null)
     * @return string corps de la requête POST
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?array $asKey = null, ?string $salt = null): string
    {
        if (strlen($payload) > self::MAX_PAYLOAD) {
            throw new RuntimeException('Charge utile trop longue pour Web Push.');
        }
        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('Secret d\'authentification invalide.');
        }
        $asKey ??= EcKeys::generate();
        $salt ??= random_bytes(16);
        $ecdh = EcKeys::ecdh(EcKeys::privateFromScalar($asKey['private'], $asKey['public']), EcKeys::publicFromPoint($uaPublic));
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublic . $asKey['public'], $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Chiffrement AES-GCM impossible.');
        }
        return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $asKey['public'] . $cipher . $tag;
    }

    /**
     * Déchiffrement côté navigateur (utilisé par les tests et le service de simulation). Écrit indépendamment de encrypt().
     * @return string|null texte clair, null si l'authentification échoue
     */
    public static function decrypt(string $body, string $uaPrivate, string $uaPublic, string $authSecret): ?string
    {
        if (strlen($body) < 86 + 17) {
            return null;
        }
        $salt = substr($body, 0, 16);
        $rs = unpack('N', substr($body, 16, 4))[1];
        $idlen = ord($body[20]);
        $asPublic = substr($body, 21, $idlen);
        $enc = substr($body, 21 + $idlen);
        if ($rs < 18 || $idlen !== 65) {
            return null;
        }
        $secret = EcKeys::ecdh(EcKeys::privateFromScalar($uaPrivate, $uaPublic), EcKeys::publicFromPoint($asPublic));
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($enc, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($enc, -16));
        if ($plain === false) {
            return null;
        }
        $plain = rtrim($plain, "\0");
        return str_ends_with($plain, "\x02") ? substr($plain, 0, -1) : null;
    }
}
