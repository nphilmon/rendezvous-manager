<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use App\Core\Router;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use Tests\DatabaseTestCase;

final class SystemApiTest extends DatabaseTestCase
{
    public function testServerStatusIsAdminOnlyAccurateAndFreeOfSecrets(): void
    {
        $users = new UserRepository($this->pdo);
        $auth = new AuthService($this->pdo, $users, new AuditLogger($this->pdo));
        $router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($router, $this->pdo);
        foreach (['admin' => 'admin', 'alice' => 'user'] as $n => $role) {
            $users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => $n, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $get = function (string $as) use ($auth, $router) {
            $c = 'rvm_session=' . $auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't')['token'];
            return Application::handle(new Request('GET', '/api/v1/system', [], '', ['cookie' => $c], '203.0.113.1'), $router);
        };
        $this->assertSame(403, $get('alice')->status);
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/system', [], '', [], '203.0.113.1'), $router)->status);
        $r = $get('admin');
        $this->assertSame(200, $r->status, $r->body);
        $d = json_decode($r->body, true)['data'];
        $this->assertSame(PHP_VERSION, $d['php']['version']);
        $this->assertTrue($d['php']['extensions']['pdo_mysql'] === true, 'ext');
        $this->assertSame([], $d['database']['migrations_pending']);
        $this->assertSame(count(glob(dirname(__DIR__, 2) . '/database/migrations/*.sql')), $d['database']['migrations_applied']);
        $this->assertSame('utf8mb4', $d['database']['charset']);
        $this->assertSame(2, $d['counts']['users']);
        $this->assertTrue(is_int($d['notifications']['pending']) && $d['time_utc'] !== '', 'notif');
        $this->assertTrue(count($d['checks']) > 10 && isset(array_column($d['checks'], 'level', 'id')['database']), 'checks ' . count($d['checks']) . json_encode(array_column($d['checks'], 'id')));
        $this->assertTrue(!preg_match('#/home/|/tmp/|password|passwd|secret|private_key#i', $r->body), 'aucun chemin du serveur ni secret : ' . substr($r->body, 0, 200));
    }
}
