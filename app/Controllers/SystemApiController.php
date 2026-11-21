<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Env;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Services\DeployCheck;
use PDO;

/**
 * État du serveur pour l'administrateur (droit « settings.admin ») : versions, migrations, file de notifications, sauvegardes, cron et contrôles de configuration.
 * Ne renvoie jamais de secret (mot de passe, clés, jeton) ni de chemin complet du serveur.
 */
final class SystemApiController
{
    public function __construct(private Authenticator $guard, private PDO $pdo, private string $root)
    {
    }

    public function show(Request $req): Response
    {
        $this->guard->requirePermission($req, 'settings.admin');
        $env = [];
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'COOKIE_SECURE', 'HSTS_MAX_AGE', 'BACKUP_DIR', 'BACKUP_KEY'] as $k) {
            $v = Env::get($k, '');
            if ($v !== '') {
                $env[$k] = $v;
            }
        }
        $migrator = new Migrator($this->pdo, $this->root . '/database/migrations');
        $applied = array_keys($migrator->applied());
        $q = fn (string $sql) => (int) $this->pdo->query($sql)->fetchColumn();
        $checks = array_map(static fn (array $c) => ['id' => $c['id'], 'level' => $c['level'], 'message' => str_replace([dirname(__DIR__, 2) . '/', dirname(__DIR__, 2)], '', $c['message'])],
            (new DeployCheck($this->root, $env))->local($this->pdo));
        $free = @disk_free_space($this->root . '/storage');
        return Response::json(['data' => [
            'app' => ['name' => (string) Config::get('app.name'), 'version' => (string) Config::get('app.version'), 'environment' => $env['APP_ENV'] ?? 'production'],
            'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'extensions' => $this->extensions(['pdo_mysql', 'mbstring', 'openssl', 'curl', 'zip', 'intl', 'sodium'])],
            'database' => ['version' => (string) $this->pdo->query('SELECT VERSION()')->fetchColumn(), 'charset' => (string) $this->pdo->query('SELECT @@character_set_database')->fetchColumn(),
                'migrations_applied' => count($applied), 'migration_latest' => $applied === [] ? null : end($applied), 'migrations_pending' => $migrator->pending()],
            'notifications' => ['pending' => $q("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'pending'"), 'failed' => $q("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'failed'"),
                'push_subscriptions' => $q('SELECT COUNT(*) FROM push_subscriptions')],
            'counts' => ['users' => $q('SELECT COUNT(*) FROM users WHERE is_active = 1'), 'calendars' => $q('SELECT COUNT(*) FROM calendars WHERE deleted_at IS NULL'),
                'appointments' => $q('SELECT COUNT(*) FROM appointments WHERE deleted_at IS NULL')],
            'storage_free_mb' => $free === false ? null : (int) ($free / 1048576),
            'time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
        ]]);
    }

    /** @param list<string> $names @return array<string,bool> */
    private function extensions(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = extension_loaded($n);
        }
        return $out;
    }
}
