<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\MaintenanceMode;
use App\Core\Response;
use App\Services\DeployCheck;
use Tests\TestCase;

final class DeployCheckTest extends TestCase
{
    private string $root;

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rvm-deploy-' . bin2hex(random_bytes(4));
        foreach (['public', 'storage/logs', 'storage/exports', 'storage/backups', 'storage/sessions', 'database/migrations'] as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }
    }

    public function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    /** @return array<string,string> id => niveau */
    private function levels(array $results): array
    {
        return array_column($results, 'level', 'id');
    }

    private function good(): array
    {
        return ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'COOKIE_SECURE' => 'true', 'APP_URL' => 'https://agenda.example.org', 'HSTS_MAX_AGE' => '31536000'];
    }

    public function testLocalChecksAcceptAHealthyProductionSetup(): void
    {
        file_put_contents($this->root . '/.env', 'X=1');
        chmod($this->root . '/.env', 0600);
        touch($this->root . '/storage/install.lock');
        touch($this->root . '/storage/logs/cron_heartbeat');
        touch($this->root . '/storage/backups/rvm-20261119-020000.sql.gz');
        $l = $this->levels((new DeployCheck($this->root, $this->good()))->local());
        foreach (['env_perms', 'app_env', 'app_debug', 'cookie_secure', 'app_url', 'hsts', 'install_lock', 'maintenance', 'storage_logs', 'storage_backups', 'backup_dir', 'cron_reminders', 'backup_recent'] as $id) {
            $this->assertSame('ok', $l[$id], $id);
        }
        $this->assertSame(0, DeployCheck::exitCode((new DeployCheck($this->root, $this->good()))->local()));
    }

    public function testLocalChecksFlagDangerousSettings(): void
    {
        file_put_contents($this->root . '/.env', 'X=1');
        chmod($this->root . '/.env', 0644);
        mkdir($this->root . '/public/sauvegardes');
        touch($this->root . '/storage/maintenance.lock');
        $env = ['APP_ENV' => 'production', 'APP_DEBUG' => 'true', 'COOKIE_SECURE' => 'false', 'APP_URL' => 'http://x', 'BACKUP_DIR' => $this->root . '/public/sauvegardes'];
        $res = (new DeployCheck($this->root, $env))->local();
        $l = $this->levels($res);
        $this->assertSame('warn', $l['env_perms'], '.env lisible par d\'autres comptes');
        $this->assertSame('fail', $l['app_debug']);
        $this->assertSame('fail', $l['cookie_secure'], 'cookie non sécurisé en production');
        $this->assertSame('warn', $l['app_url']);
        $this->assertSame('warn', $l['hsts']);
        $this->assertSame('fail', $l['install_lock'], 'assistant non verrouillé');
        $this->assertSame('warn', $l['maintenance']);
        $this->assertSame('fail', $l['backup_dir'], 'sauvegardes dans public/');
        $this->assertSame('warn', $l['cron_reminders']);
        $this->assertSame('warn', $l['backup_recent']);
        $this->assertSame(1, DeployCheck::exitCode($res));
        // une sauvegarde trop ancienne
        $old = $this->root . '/storage/backups/rvm-20200101-000000.sql.gz';
        touch($old, time() - 5 * 86400);
        $this->assertSame('warn', $this->levels((new DeployCheck($this->root, $this->good()))->local())['backup_recent']);
        unlink($this->root . '/storage/backups/../backups/rvm-20200101-000000.sql.gz');
        rmdir($this->root . '/storage/sessions');
        $this->assertSame('warn', $this->levels((new DeployCheck($this->root, $this->good()))->local())['storage_sessions'], 'dossier des sessions d\'installation : simple avertissement');
        rmdir($this->root . '/storage/logs');
        $this->assertSame('fail', $this->levels((new DeployCheck($this->root, $this->good()))->local())['storage_logs']);
    }

    /** @param array<string,int> $status @param array<string,string> $headers */
    private function fake(array $status, array $headers = [], ?string $error = null): callable
    {
        $h = $headers + ['content-security-policy' => "default-src 'self'", 'x-frame-options' => 'DENY', 'x-content-type-options' => 'nosniff', 'strict-transport-security' => 'max-age=1'];
        return static function (string $url) use ($status, $h, $error): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            return ['status' => $status[$path] ?? 404, 'headers' => $path === '/login' ? $h : [], 'error' => $error];
        };
    }

    public function testRemoteChecksPassWhenNothingSensitiveIsServed(): void
    {
        $res = (new DeployCheck($this->root, []))->withHttp($this->fake(['/login' => 200, '/api/v1/health' => 200, '/install' => 302]))->remote('https://agenda.example.org');
        $l = $this->levels($res);
        foreach (['https', 'login_page', 'csp', 'frame', 'nosniff', 'hsts_header', 'banner', 'health', 'exposure', 'installer'] as $id) {
            $this->assertSame('ok', $l[$id], $id . ' ' . json_encode($res));
        }
        $this->assertSame(0, DeployCheck::exitCode($res));
    }

    public function testRemoteChecksDetectExposedFilesAndMissingProtections(): void
    {
        // racine web = racine du dépôt : .env et la configuration sont servis, l'assistant est ouvert, aucun en-tête de sécurité
        $http = static fn (string $u): array => ['status' => in_array(parse_url($u, PHP_URL_PATH), ['/login', '/api/v1/health', '/.env', '/config/app.php', '/install'], true) ? 200 : 404,
            'headers' => ['x-powered-by' => 'PHP/8.3.6'], 'error' => null];
        $res = (new DeployCheck($this->root, []))->withHttp($http)->remote('http://agenda.example.org');
        $l = $this->levels($res);
        $this->assertSame('warn', $l['https']);
        $this->assertSame('fail', $l['csp']);
        $this->assertSame('warn', $l['hsts_header']);
        $this->assertSame('warn', $l['banner']);
        $this->assertSame('fail', $l['exposure']);
        $msg = implode(' ', array_column($res, 'message'));
        $this->assertTrue(str_contains($msg, '/.env') && str_contains($msg, '/config/app.php'), 'fichiers exposés nommés');
        $this->assertSame('fail', $l['installer']);
        $this->assertSame(1, DeployCheck::exitCode($res));
    }

    public function testRemoteChecksReportUnreachableSiteAndWrongDocumentRoot(): void
    {
        $down = (new DeployCheck($this->root, []))->withHttp($this->fake([], [], 'SSL certificate problem'))->remote('https://x.example');
        $this->assertSame('fail', $this->levels($down)['reachable']);
        $this->assertTrue(str_contains(implode(' ', array_column($down, 'message')), 'certificat'));
        // racine web sans application : tout est 404 (cas « nginx 404 »)
        $empty = (new DeployCheck($this->root, []))->withHttp($this->fake([]))->remote('https://x.example');
        $l = $this->levels($empty);
        $this->assertSame('fail', $l['login_page']);
        $this->assertSame('fail', $l['health']);
        $this->assertTrue(str_contains(implode(' ', array_column($empty, 'message')), 'racine web'), 'le message évoque la racine web');
    }

    public function testMaintenanceModeAndHsts(): void
    {
        $lock = $this->root . '/storage/maintenance.lock';
        $this->assertTrue(!MaintenanceMode::active($lock));
        touch($lock);
        $this->assertTrue(MaintenanceMode::active($lock));
        $page = MaintenanceMode::response(false);
        $api = MaintenanceMode::response(true);
        $this->assertSame([503, 503, '120'], [$page->status, $api->status, $api->headers['Retry-After']]);
        $this->assertTrue(str_contains($page->body, 'Maintenance') && str_starts_with($api->headers['Content-Type'], 'application/json'));
        $this->assertTrue(!str_contains($page->body . $api->body, '/home/'), 'aucun détail interne');
        $this->assertSame(null, Response::hsts(0));
        $this->assertSame('max-age=31536000', Response::hsts(31536000));
        $this->assertSame(null, Response::hsts(-5));
    }
}
