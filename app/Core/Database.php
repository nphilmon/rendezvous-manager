<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    /** @param array<string,mixed>|null $cfg */
    public static function connection(?array $cfg = null): PDO
    {
        if ($cfg === null && self::$pdo !== null) {
            return self::$pdo;
        }
        $pdo = self::connect($cfg ?? (array) Config::get('database'));
        if ($cfg === null) {
            self::$pdo = $pdo;
        }
        return $pdo;
    }

    /** @param array<string,mixed> $cfg */
    public static function dsn(array $cfg, bool $withDb = true): string
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], (int) $cfg['port']);
        if ($withDb && ($cfg['name'] ?? '') !== '') {
            $dsn .= ';dbname=' . $cfg['name'];
        }
        return $dsn;
    }

    /**
     * Options PDO. Le mode TLS "verify" vérifie le certificat ET l'identité du serveur ;
     * la vérification n'est jamais désactivée silencieusement.
     * @param array<string,mixed> $cfg
     * @return array<int,mixed>
     */
    public static function options(array $cfg): array
    {
        $o = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
        ];
        $mode = (string) ($cfg['ssl_mode'] ?? 'disabled');
        if ($mode === 'required' || $mode === 'verify') {
            if (($cfg['ssl_ca'] ?? '') !== '') {
                $o[PDO::MYSQL_ATTR_SSL_CA] = $cfg['ssl_ca'];
            }
            $o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = ($mode === 'verify');
        }
        return $o;
    }

    /** @param array<string,mixed> $cfg */
    private static function connect(array $cfg, bool $withDb = true): PDO
    {
        if (($cfg['driver'] ?? 'mysql') !== 'mysql') {
            throw new RuntimeException('Seuls MySQL et MariaDB sont pris en charge.');
        }
        return new PDO(self::dsn($cfg, $withDb), (string) $cfg['user'], (string) $cfg['password'], self::options($cfg));
    }

    /** @param array<string,mixed> $cfg Connexion sans base sélectionnée (installateur). */
    public static function connectServer(array $cfg): PDO
    {
        return self::connect($cfg, false);
    }
}
