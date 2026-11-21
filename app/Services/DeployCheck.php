<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Contrôles de déploiement : configuration locale (version de PHP, droits, mode production, sauvegardes, cron) et, avec une URL,
 * exposition depuis Internet (fichiers sensibles inaccessibles, HTTPS, en-têtes de sécurité). Chaque résultat est « ok », « warn » ou « fail ».
 */
final class DeployCheck
{
    /** Chemins qui ne doivent JAMAIS être servis : 200 = fichier exposé (échec). */
    public const SENSITIVE_PATHS = ['/.env', '/.env.example', '/.git/HEAD', '/.git/config', '/app/Core/Config.php', '/config/app.php', '/storage/install.lock', '/storage/logs/',
        '/storage/backups/', '/database/schema.sql', '/composer.json', '/README.md', '/tests/run.php', '/scripts/migrate.php', '/workers/reminder_worker.php',
        '/installer/InstallerController.php', '/docs/securite.md'];

    /** @var callable(string):array{status:int,headers:array<string,string>,error:?string} */
    private $http;

    /**
     * @param array<string,string> $env variables de configuration (.env) déjà lues
     * @param callable(string):array{status:int,headers:array<string,string>,error:?string}|null $http récupération HTTP (GET sans suivre les redirections) ; défaut : curl avec vérification TLS
     */
    public function __construct(private string $root, private array $env, ?callable $http = null)
    {
        $this->http = $http ?? self::curl(...);
    }

    /** @param callable(string):array{status:int,headers:array<string,string>,error:?string} $http copie avec un autre client HTTP (tests) */
    public function withHttp(callable $http): self
    {
        $c = clone $this;
        $c->http = $http;
        return $c;
    }

    /** @return list<array{id:string,level:string,message:string}> */
    public function local(?PDO $pdo = null, ?int $now = null): array
    {
        $now ??= time();
        $r = [];
        $add = static function (string $id, string $level, string $msg) use (&$r): void {
            $r[] = ['id' => $id, 'level' => $level, 'message' => $msg];
        };
        $add('php', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'fail', 'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.3.0', '>=') ? '' : ' : 8.3 minimum requis'));
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'json'] as $ext) {
            $add("ext_$ext", extension_loaded($ext) ? 'ok' : 'fail', "Extension $ext" . (extension_loaded($ext) ? '' : ' manquante'));
        }
        foreach (['curl' => 'Web Push', 'zip' => 'export XLSX'] as $ext => $why) {
            $add("ext_$ext", extension_loaded($ext) ? 'ok' : 'warn', "Extension $ext" . (extension_loaded($ext) ? '' : " absente ($why indisponible)"));
        }

        $envFile = $this->root . '/.env';
        if (!is_file($envFile)) {
            $add('env_file', 'fail', '.env absent (installation non terminée ?)');
        } else {
            $perm = fileperms($envFile) & 0777;
            $add('env_perms', ($perm & 0077) === 0 ? 'ok' : 'warn', sprintf('.env en droits %04o', $perm) . (($perm & 0077) === 0 ? '' : ' : lisible par d\'autres comptes, appliquez chmod 600 .env'));
        }
        $e = $this->env;
        $prod = ($e['APP_ENV'] ?? 'production') === 'production';
        $add('app_env', $prod ? 'ok' : 'warn', 'APP_ENV=' . ($e['APP_ENV'] ?? 'production (défaut)') . ($prod ? '' : ' : utilisez production en ligne'));
        $debug = in_array(strtolower($e['APP_DEBUG'] ?? 'false'), ['1', 'true', 'yes', 'on'], true);
        $add('app_debug', $debug ? 'fail' : 'ok', $debug ? 'APP_DEBUG actif : des messages d\'erreur internes peuvent s\'afficher' : 'APP_DEBUG désactivé');
        $cookie = strtolower($e['COOKIE_SECURE'] ?? ($prod ? 'true' : 'false'));
        $add('cookie_secure', in_array($cookie, ['1', 'true', 'yes', 'on'], true) ? 'ok' : ($prod ? 'fail' : 'warn'), 'COOKIE_SECURE=' . $cookie . (in_array($cookie, ['1', 'true', 'yes', 'on'], true) ? '' : ' : le cookie de session circulerait en clair'));
        $url = $e['APP_URL'] ?? '';
        $add('app_url', str_starts_with($url, 'https://') ? 'ok' : 'warn', $url === '' ? 'APP_URL non renseignée' : 'APP_URL=' . $url . (str_starts_with($url, 'https://') ? '' : ' : https attendu'));
        $hsts = (int) ($e['HSTS_MAX_AGE'] ?? 0);
        $add('hsts', $hsts > 0 ? 'ok' : 'warn', $hsts > 0 ? "HSTS_MAX_AGE=$hsts" : 'HSTS désactivé (HSTS_MAX_AGE=0) : à activer une fois HTTPS définitif');

        $add('install_lock', is_file($this->root . '/storage/install.lock') ? 'ok' : 'fail', is_file($this->root . '/storage/install.lock') ? 'Installation verrouillée' : 'storage/install.lock absent : l\'assistant /install est ouvert ou l\'installation est inachevée');
        $add('maintenance', is_file($this->root . '/storage/maintenance.lock') ? 'warn' : 'ok', is_file($this->root . '/storage/maintenance.lock') ? 'Mode maintenance actif (storage/maintenance.lock)' : 'Pas de maintenance en cours');
        foreach (['logs', 'exports', 'backups', 'sessions'] as $d) {
            $p = $this->root . '/storage/' . $d;
            $add("storage_$d", is_dir($p) && is_writable($p) ? 'ok' : ($d === 'sessions' ? 'warn' : 'fail'), "storage/$d" . (is_dir($p) && is_writable($p) ? ' inscriptible' : ' absent ou non inscriptible'));
        }
        $backupDir = ($e['BACKUP_DIR'] ?? '') !== '' ? $e['BACKUP_DIR'] : $this->root . '/storage/backups';
        $real = realpath($backupDir) ?: $backupDir;
        $public = realpath($this->root . '/public') ?: $this->root . '/public';
        $add('backup_dir', str_starts_with($real . '/', $public . '/') ? 'fail' : 'ok', str_starts_with($real . '/', $public . '/') ? 'BACKUP_DIR est dans public/ : les sauvegardes seraient téléchargeables' : 'Dossier de sauvegarde hors de public/');

        $key = $e['BACKUP_KEY'] ?? '';
        $add('backup_key', $key !== '' ? 'ok' : 'warn', $key !== '' ? 'Sauvegardes chiffrées (BACKUP_KEY renseignée) : conservez la clé hors du serveur' : 'Sauvegardes NON chiffrées (BACKUP_KEY vide) : php scripts/generate_backup_key.php');

        $hb = $this->root . '/storage/logs/cron_heartbeat';
        $age = is_file($hb) ? $now - (int) filemtime($hb) : null;
        $add('cron_reminders', $age !== null && $age < 300 ? 'ok' : 'warn', $age === null ? 'Aucun passage du worker de rappels (cron absent ?)' : "Worker de rappels : dernier passage il y a {$age} s" . ($age < 300 ? '' : ' (cron arrêté ?)'));
        $newest = 0;
        foreach (glob($backupDir . '/rvm-*.sql.gz') ?: [] as $f) {
            $newest = max($newest, (int) filemtime($f));
        }
        $bAge = $newest > 0 ? $now - $newest : null;
        $add('backup_recent', $bAge !== null && $bAge < 36 * 3600 ? 'ok' : 'warn', $bAge === null ? 'Aucune sauvegarde' : 'Dernière sauvegarde il y a ' . intdiv($bAge, 3600) . ' h' . ($bAge < 36 * 3600 ? '' : ' : le cron de sauvegarde ne tourne plus ?'));

        if ($pdo !== null) {
            try {
                $pdo->query('SELECT 1');
                $pending = (new \App\Core\Migrator($pdo, $this->root . '/database/migrations'))->pending();
                $add('database', 'ok', 'Base accessible');
                $add('migrations', $pending === [] ? 'ok' : 'fail', $pending === [] ? 'Schéma à jour' : 'Migrations en attente : ' . implode(', ', $pending) . ' (php scripts/migrate.php)');
            } catch (\Throwable) {
                $add('database', 'fail', 'Base inaccessible');
            }
        }
        return $r;
    }

    /** @return list<array{id:string,level:string,message:string}> */
    public function remote(string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');
        $r = [];
        $add = static function (string $id, string $level, string $msg) use (&$r): void {
            $r[] = ['id' => $id, 'level' => $level, 'message' => $msg];
        };
        $add('https', str_starts_with($base, 'https://') ? 'ok' : 'warn', str_starts_with($base, 'https://') ? 'Adresse en HTTPS' : 'Adresse en HTTP : en production, HTTPS est obligatoire');
        $home = ($this->http)($base . '/login');
        if ($home['error'] !== null) {
            $add('reachable', 'fail', 'Site injoignable ou certificat invalide : ' . $home['error']);
            return $r;
        }
        $add('login_page', $home['status'] === 200 ? 'ok' : 'fail', "/login → {$home['status']}" . ($home['status'] === 200 ? '' : ' (attendu 200 : racine web incorrecte, réécriture d\'URL absente ou application non installée)'));
        $h = $home['headers'];
        $add('csp', isset($h['content-security-policy']) ? 'ok' : 'fail', isset($h['content-security-policy']) ? 'Content-Security-Policy présente' : 'Content-Security-Policy absente');
        $add('frame', isset($h['x-frame-options']) ? 'ok' : 'warn', isset($h['x-frame-options']) ? 'X-Frame-Options présent' : 'X-Frame-Options absent');
        $add('nosniff', isset($h['x-content-type-options']) ? 'ok' : 'warn', isset($h['x-content-type-options']) ? 'X-Content-Type-Options présent' : 'X-Content-Type-Options absent');
        $add('hsts_header', isset($h['strict-transport-security']) ? 'ok' : 'warn', isset($h['strict-transport-security']) ? 'Strict-Transport-Security présent' : 'Strict-Transport-Security absent (HSTS_MAX_AGE, ou réglage du serveur web)');
        $add('banner', isset($h['x-powered-by']) ? 'warn' : 'ok', isset($h['x-powered-by']) ? 'X-Powered-By expose « ' . $h['x-powered-by'] . ' » (expose_php=Off, ou en-tête retiré)' : 'Version de PHP non annoncée');

        $health = ($this->http)($base . '/api/v1/health');
        $add('health', $health['status'] === 200 ? 'ok' : 'fail', "/api/v1/health → {$health['status']}");
        $exposed = [];
        $odd = [];
        foreach (self::SENSITIVE_PATHS as $p) {
            $x = ($this->http)($base . $p);
            if ($x['error'] === null && $x['status'] === 200) {
                $exposed[] = $p;
            } elseif ($x['error'] === null && $x['status'] >= 500) {
                $odd[] = "$p → {$x['status']}";
            }
        }
        $add('exposure', $exposed === [] ? 'ok' : 'fail', $exposed === [] ? count(self::SENSITIVE_PATHS) . ' chemins sensibles testés : aucun servi' : 'FICHIERS EXPOSÉS sur Internet : ' . implode(', ', $exposed) . ' (la racine web doit être public/)');
        if ($odd !== []) {
            $add('exposure_errors', 'warn', 'Erreurs serveur sur des chemins sensibles : ' . implode(', ', $odd));
        }
        $inst = ($this->http)($base . '/install');
        $add('installer', $inst['status'] === 200 ? 'fail' : 'ok', $inst['status'] === 200 ? '/install accessible : l\'assistant n\'est pas verrouillé' : "/install → {$inst['status']} (verrouillé)");
        return $r;
    }

    /** @param list<array{id:string,level:string,message:string}> $results */
    public static function exitCode(array $results): int
    {
        foreach ($results as $x) {
            if ($x['level'] === 'fail') {
                return 1;
            }
        }
        return 0;
    }

    /** @return array{status:int,headers:array<string,string>,error:?string} */
    public static function curl(string $url): array
    {
        $ch = curl_init($url);
        $headers = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'RendezVousManager-deploy-check', CURLOPT_MAXFILESIZE => 2000000,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            }]);
        $body = curl_exec($ch);
        $err = $body === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $headers, 'error' => $err];
    }
}
