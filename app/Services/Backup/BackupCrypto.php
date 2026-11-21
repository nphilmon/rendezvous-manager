<?php
declare(strict_types=1);

namespace App\Services\Backup;

use RuntimeException;

/**
 * Chiffrement des sauvegardes (AES-256-GCM par blocs de 1 Mio, extension openssl, sans dépendance).
 *
 * Format « RVMENC1 » : magic (7 o) · sel (16 o) · préfixe de nonce (8 o) · puis, pour chaque bloc, longueur du chiffré (4 o, big-endian) · chiffré · étiquette GCM (16 o).
 * Clé du fichier = HKDF-SHA256(clé maîtresse de 32 octets, sel). Nonce d'un bloc = préfixe ‖ compteur (4 o). Les données authentifiées (AAD) contiennent
 * l'en-tête, le compteur et un indicateur « dernier bloc » : une modification, un réordonnancement ou une TRONCATURE du fichier sont détectés.
 */
final class BackupCrypto
{
    public const MAGIC = 'RVMENC1';
    private const CHUNK = 1048576;
    private const TAG = 16;

    /** Décode BACKUP_KEY (base64 de 32 octets aléatoires) ; exception si invalide. */
    public static function parseKey(string $b64): string
    {
        $raw = base64_decode(trim($b64), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('BACKUP_KEY invalide : base64 de 32 octets attendu (php scripts/generate_backup_key.php).');
        }
        return $raw;
    }

    public static function isEncrypted(string $path): bool
    {
        $h = @file_get_contents($path, false, null, 0, strlen(self::MAGIC));
        return $h === self::MAGIC;
    }

    public static function encryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new RuntimeException('Fichier de sauvegarde inaccessible.');
        }
        try {
            $salt = random_bytes(16);
            $prefix = random_bytes(8);
            $header = self::MAGIC . $salt . $prefix;
            fwrite($dst, $header);
            $fk = hash_hkdf('sha256', $key, 32, 'rvm-backup-v1', $salt);
            $n = 0;
            $prev = fread($src, self::CHUNK);
            while (true) {
                $next = $prev === '' || $prev === false ? '' : fread($src, self::CHUNK);
                $final = $next === '' || $next === false;
                self::writeChunk($dst, $fk, $header, $prefix, $n++, (string) $prev, $final);
                if ($final) {
                    break;
                }
                $prev = $next;
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    /** @throws RuntimeException clé incorrecte, fichier altéré ou tronqué */
    public static function decryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new RuntimeException('Fichier de sauvegarde inaccessible.');
        }
        try {
            fseek($src, 0, SEEK_END);
            $size = (int) ftell($src); // pas filesize() : son résultat est mis en cache par PHP
            fseek($src, 0);
            $header = (string) fread($src, strlen(self::MAGIC) + 24);
            if (strlen($header) !== strlen(self::MAGIC) + 24 || !str_starts_with($header, self::MAGIC)) {
                throw new RuntimeException('Format de sauvegarde chiffrée non reconnu.');
            }
            $salt = substr($header, 7, 16);
            $prefix = substr($header, 23, 8);
            $fk = hash_hkdf('sha256', $key, 32, 'rvm-backup-v1', $salt);
            $n = 0;
            while (true) {
                $lenRaw = fread($src, 4);
                if ($lenRaw === false || strlen($lenRaw) !== 4) {
                    throw new RuntimeException('Sauvegarde chiffrée tronquée.');
                }
                $len = unpack('N', $lenRaw)[1];
                if ($len > self::CHUNK) {
                    throw new RuntimeException('Sauvegarde chiffrée corrompue.');
                }
                $body = '';
                while (strlen($body) < $len + self::TAG) {
                    $part = fread($src, $len + self::TAG - strlen($body));
                    if ($part === false || $part === '') {
                        throw new RuntimeException('Sauvegarde chiffrée tronquée.');
                    }
                    $body .= $part;
                }
                $final = ftell($src) >= $size;
                $plain = openssl_decrypt(substr($body, 0, $len), 'aes-256-gcm', $fk, OPENSSL_RAW_DATA, $prefix . pack('N', $n), substr($body, $len), self::aad($header, $n, $final));
                if ($plain === false) {
                    throw new RuntimeException('Déchiffrement impossible : mauvaise clé, fichier altéré ou tronqué.');
                }
                fwrite($dst, $plain);
                $n++;
                if ($final) {
                    break;
                }
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    /** @param resource $dst */
    private static function writeChunk($dst, string $fk, string $header, string $prefix, int $n, string $plain, bool $final): void
    {
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $fk, OPENSSL_RAW_DATA, $prefix . pack('N', $n), $tag, self::aad($header, $n, $final), self::TAG);
        if ($ct === false) {
            throw new RuntimeException('Chiffrement impossible (extension openssl).');
        }
        fwrite($dst, pack('N', strlen($ct)) . $ct . $tag);
    }

    private static function aad(string $header, int $n, bool $final): string
    {
        return $header . pack('N', $n) . ($final ? "\x01" : "\x00");
    }
}
