<?php
declare(strict_types=1);

namespace Installer;

use App\Core\Database;
use App\Core\Migrator;
use PDO;
use PDOException;

final class DatabaseSetup
{
    public const MIN_MYSQL = '8.0.16';
    public const MIN_MARIADB = '10.5.0';

    public function __construct(private string $envFile, private string $migrationsDir)
    {
    }

    /**
     * Valide les champs du formulaire.
     * @param array<string,mixed> $in
     * @return array{0:array<string,mixed>,1:list<string>} [config normalisée, erreurs]
     */
    public static function normalize(array $in): array
    {
        $e = [];
        $type = (string) ($in['type'] ?? 'mysql');
        if (!in_array($type, ['mysql', 'mariadb'], true)) {
            $e[] = 'Type de base invalide.';
        }
        $host = trim((string) ($in['host'] ?? ''));
        if ($host === '' || !preg_match('/^[A-Za-z0-9._:\-\[\]]{1,253}$/', $host)) {
            $e[] = 'Adresse du serveur invalide.';
        }
        $port = (int) ($in['port'] ?? 3306);
        if ($port < 1 || $port > 65535) {
            $e[] = 'Port invalide.';
        }
        $name = trim((string) ($in['name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $name)) {
            $e[] = 'Nom de base invalide (lettres, chiffres, _ $ -).';
        }
        $user = trim((string) ($in['user'] ?? ''));
        if ($user === '' || strlen($user) > 80 || preg_match('/[\r\n]/', $user)) {
            $e[] = 'Utilisateur SQL invalide.';
        }
        $password = (string) ($in['password'] ?? '');
        if (preg_match('/[\r\n\0]/', $password)) {
            $e[] = 'Le mot de passe ne doit pas contenir de saut de ligne.';
        }
        if (str_contains($password, '"') && str_contains($password, "'")) {
            $e[] = 'Le mot de passe ne peut pas contenir à la fois des guillemets simples et doubles (limite du fichier .env).';
        }
        $ssl = (string) ($in['ssl_mode'] ?? 'verify');
        if (!in_array($ssl, ['disabled', 'required', 'verify'], true)) {
            $e[] = 'Mode TLS invalide.';
        }
        $ca = trim((string) ($in['ssl_ca'] ?? ''));
        if ($ca !== '' && (!is_file($ca) || !is_readable($ca))) {
            $e[] = 'Certificat CA illisible sur le serveur.';
        }
        return [[
            'type' => $type, 'driver' => 'mysql', 'host' => $host, 'port' => $port, 'name' => $name,
            'user' => $user, 'password' => $password, 'ssl_mode' => $ssl, 'ssl_ca' => $ca,
        ], $e];
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array{ok:bool,message:string,pdo:?PDO,version:string,flavor:string}
     */
    public function connect(array $cfg): array
    {
        try {
            $pdo = new PDO(Database::dsn($cfg), $cfg['user'], $cfg['password'], Database::options($cfg));
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            return ['ok' => true, 'message' => 'Connexion réussie.', 'pdo' => $pdo, 'version' => $version, 'flavor' => self::flavor($version)];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => self::explain($e, $cfg), 'pdo' => null, 'version' => '', 'flavor' => ''];
        }
    }

    public static function flavor(string $version): string
    {
        return stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
    }

    /** @return array{ok:bool,message:string} */
    public static function versionCheck(string $version, string $declaredType): array
    {
        $flavor = self::flavor($version);
        preg_match('/\d+\.\d+\.\d+/', $version, $m);
        $num = $m[0] ?? '0.0.0';
        $min = $flavor === 'mariadb' ? self::MIN_MARIADB : self::MIN_MYSQL;
        if (version_compare($num, $min, '<')) {
            return ['ok' => false, 'message' => ucfirst($flavor) . " $num est trop ancien (minimum $min)."];
        }
        $note = $flavor !== $declaredType ? " Attention : le serveur est de type $flavor alors que « $declaredType » a été choisi." : '';
        return ['ok' => true, 'message' => ucfirst($flavor) . " $num compatible.$note"];
    }

    /** Message lisible ; ne contient jamais le mot de passe. */
    private static function explain(PDOException $e, array $cfg): string
    {
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());
        $map = [
            1045 => 'Accès refusé : utilisateur ou mot de passe incorrect.',
            1044 => 'Cet utilisateur n\'a pas accès à cette base.',
            1049 => 'La base « ' . $cfg['name'] . ' » n\'existe pas : créez-la d\'abord (voir docs/configuration_mysql.md).',
            2002 => 'Serveur injoignable (adresse, port ou pare-feu).',
            2006 => 'Le serveur a fermé la connexion.',
        ];
        if (isset($map[$code])) {
            return $map[$code];
        }
        $msg = str_replace((string) $cfg['password'], '***', $e->getMessage());
        if ($cfg['password'] === '') {
            $msg = $e->getMessage();
        }
        return 'Échec de connexion : ' . mb_substr($msg, 0, 200);
    }

    /**
     * Teste réellement les privilèges nécessaires (tables temporaires nommées, supprimées ensuite).
     * @return list<array{privilege:string,ok:bool,message:string}>
     */
    public function checkPermissions(PDO $pdo): array
    {
        $p = 'rvm_perm_' . bin2hex(random_bytes(3));
        $c = $p . '_c';
        $steps = [
            'CREATE' => "CREATE TABLE `$p` (id INT NOT NULL PRIMARY KEY, v INT NULL) ENGINE=InnoDB",
            'REFERENCES' => "CREATE TABLE `$c` (id INT NOT NULL PRIMARY KEY, pid INT NOT NULL, CONSTRAINT `{$c}_fk` FOREIGN KEY (pid) REFERENCES `$p` (id)) ENGINE=InnoDB",
            'ALTER' => "ALTER TABLE `$p` ADD COLUMN w INT NULL",
            'INDEX' => "CREATE INDEX `{$p}_i` ON `$p` (w)",
            'INSERT' => "INSERT INTO `$p` (id, v) VALUES (1, 1)",
            'UPDATE' => "UPDATE `$p` SET v = 2 WHERE id = 1",
            'SELECT' => "SELECT * FROM `$p`",
            'DELETE' => "DELETE FROM `$p` WHERE id = 1",
        ];
        $out = [];
        foreach ($steps as $priv => $sql) {
            try {
                if ($priv === 'SELECT') {
                    $pdo->query($sql)->fetchAll(); // consomme le résultat avant les requêtes suivantes
                } else {
                    $pdo->exec($sql);
                }
                $out[] = ['privilege' => $priv, 'ok' => true, 'message' => 'OK'];
            } catch (PDOException $e) {
                $out[] = ['privilege' => $priv, 'ok' => false, 'message' => 'Refusé ou impossible'];
            }
        }
        foreach ([$c, $p] as $t) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `$t`");
                $ok = true;
            } catch (PDOException) {
                $ok = false;
            }
            if ($t === $p) {
                $out[] = ['privilege' => 'DROP', 'ok' => $ok, 'message' => $ok ? 'OK' : 'Refusé : supprimez la table ' . $p . ' manuellement'];
            }
        }
        return $out;
    }

    /**
     * État de la base : empty | compatible | foreign. Ne modifie rien.
     * @return array<string,mixed>
     */
    public function inspect(PDO $pdo, string $dbName): array
    {
        $st = $pdo->prepare('SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $st->execute([$dbName]);
        $charset = (string) ($st->fetchColumn() ?: '');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $has = in_array('schema_migrations', $tables, true);
        $m = new Migrator($pdo, $this->migrationsDir);
        $applied = $has ? array_keys($m->applied()) : [];
        $available = array_keys($m->available());
        $state = $tables === [] ? 'empty' : ($has ? 'compatible' : 'foreign');
        return [
            'state' => $state,
            'table_count' => count($tables),
            'charset' => $charset,
            'charset_ok' => str_starts_with($charset, 'utf8mb4'),
            'applied' => $applied,
            'pending' => array_values(array_diff($available, $applied)),
            'unknown_applied' => array_values(array_diff($applied, $available)),
        ];
    }

    /** @return list<string> versions appliquées. Refuse une base non reconnue. */
    public function migrate(PDO $pdo, string $dbName): array
    {
        $i = $this->inspect($pdo, $dbName);
        if ($i['state'] === 'foreign') {
            throw new \RuntimeException('La base contient des tables inconnues : aucune migration n\'est appliquée (aucune table n\'est jamais supprimée).');
        }
        if ($i['unknown_applied']) {
            throw new \RuntimeException('Le schéma de la base est plus récent que cette version de l\'application.');
        }
        return (new Migrator($pdo, $this->migrationsDir))->migrate();
    }

    /**
     * Écrit/fusionne les paramètres DB_* dans .env (écriture atomique, permissions 0600).
     * Les autres clés du fichier sont conservées.
     * @param array<string,mixed> $cfg
     */
    public function writeEnv(array $cfg): void
    {
        $new = [
            'DB_DRIVER' => 'mysql', 'DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_NAME' => $cfg['name'],
            'DB_USER' => $cfg['user'], 'DB_PASSWORD' => $cfg['password'], 'DB_SSL_MODE' => $cfg['ssl_mode'],
            'DB_SSL_CA' => $cfg['ssl_ca'], 'DB_TYPE' => $cfg['type'],
        ];
        $lines = is_file($this->envFile) ? (preg_split('/\R/', rtrim((string) file_get_contents($this->envFile))) ?: []) : [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $k = trim(explode('=', $line, 2)[0]);
            if (isset($new[$k]) && !str_starts_with(trim($line), '#')) {
                $lines[$i] = $k . '=' . self::quote($new[$k]);
                $seen[$k] = true;
            }
        }
        foreach ($new as $k => $v) {
            if (!isset($seen[$k])) {
                $lines[] = $k . '=' . self::quote($v);
            }
        }
        $tmp = $this->envFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, implode("\n", $lines) . "\n");
        chmod($tmp, 0600);
        rename($tmp, $this->envFile);
    }

    private static function quote(string $v): string
    {
        if ($v === '' || preg_match('/^[A-Za-z0-9_.\/:@\-]+$/', $v)) {
            return $v;
        }
        return str_contains($v, '"') ? "'" . $v . "'" : '"' . $v . '"'; // pas d'échappement dans notre parseur
    }

    /** @return array<string,mixed>|null configuration lue depuis .env, null si absente */
    public function loadConfig(): ?array
    {
        if (!is_file($this->envFile)) {
            return null;
        }
        $v = \App\Core\Env::parse((string) file_get_contents($this->envFile));
        if (($v['DB_NAME'] ?? '') === '' || ($v['DB_USER'] ?? '') === '') {
            return null;
        }
        return [
            'type' => $v['DB_TYPE'] ?? 'mysql', 'driver' => 'mysql', 'host' => $v['DB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($v['DB_PORT'] ?? 3306), 'name' => $v['DB_NAME'], 'user' => $v['DB_USER'],
            'password' => $v['DB_PASSWORD'] ?? '', 'ssl_mode' => $v['DB_SSL_MODE'] ?? 'verify', 'ssl_ca' => $v['DB_SSL_CA'] ?? '',
        ];
    }
}
