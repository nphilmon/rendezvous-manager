<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\UserAdminService;
use Tests\DatabaseTestCase;

final class UserAdminTest extends DatabaseTestCase
{
    private Router $router;
    private AuthService $auth;
    private UserRepository $users;
    /** @var array<string,array{cookie:string,csrf:string}> */
    private array $sessions = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->sessions = [];
        $this->users = new UserRepository($this->pdo);
        $this->auth = new AuthService($this->pdo, $this->users, new AuditLogger($this->pdo));
        $this->router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($this->router, $this->pdo);
        foreach (['admin' => 'admin', 'alice' => 'user'] as $n => $role) {
            $this->users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => ucfirst($n), 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
    }

    private function call(string $as, string $method, string $path, array $body = [], array $query = [], string $pw = 'Correct-Horse-9'): Response
    {
        $this->sessions[$as] ??= (function () use ($as, $pw) {
            $r = $this->auth->login($as, $pw, '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
        $h = ['cookie' => $this->sessions[$as]['cookie']];
        if ($method !== 'GET') {
            $h['x-csrf-token'] = $this->sessions[$as]['csrf'];
        }
        return Application::handle(new Request($method, $path, $query, $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
    }

    private function j(Response $r): array
    {
        return json_decode($r->body, true) ?? [];
    }

    private function newUser(array $over = []): array
    {
        $r = $this->call('admin', 'POST', '/api/v1/users', $over + ['username' => 'carol', 'email' => 'carol@example.org', 'first_name' => 'Carol', 'last_name' => 'Durand', 'role' => 'user', 'timezone' => 'Europe/Paris']);
        $this->assertSame(201, $r->status, $r->body);
        return $this->j($r);
    }

    public function testOnlyUserManagersCanAdminister(): void
    {
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/users', [], '', [], '203.0.113.1'), $this->router)->status);
        foreach ([['GET', '/api/v1/users'], ['GET', '/api/v1/roles'], ['GET', '/api/v1/users/1'], ['POST', '/api/v1/users/1/reset-password'], ['POST', '/api/v1/users/1/deactivate']] as [$m, $p]) {
            $this->assertSame(403, $this->call('alice', $m, $p)->status, "$m $p");
        }
        $this->assertSame(200, $this->call('admin', 'GET', '/api/v1/users')->status);
        $noCsrf = Application::handle(new Request('POST', '/api/v1/users', [], '{}', ['cookie' => $this->sessions['admin']['cookie']], '203.0.113.1'), $this->router);
        $this->assertSame(403, $noCsrf->status, 'CSRF exigé');
    }

    public function testCreateReturnsTemporaryPasswordOnceAndForcesChange(): void
    {
        $r = $this->newUser();
        $pw = $r['temporary_password'];
        $this->assertTrue(strlen($pw) === 16 && !PasswordHasher::validateStrength($pw, 12, 'carol'), 'mot de passe provisoire fort');
        $this->assertTrue(!str_contains(json_encode($r), 'password_hash') && $r['data']['must_change_password'] === true);
        $id = $r['data']['id'];
        $this->assertTrue(!str_contains($this->call('admin', 'GET', "/api/v1/users/$id")->body, $pw), 'le mot de passe n\'est plus renvoyé');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE details LIKE " . $this->pdo->quote("%$pw%"))->fetchColumn(), 'jamais journalisé');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='user.create'")->fetchColumn());
        // connexion : tout est bloqué sauf le changement de mot de passe
        $this->assertSame(403, $this->call('carol', 'GET', '/api/v1/calendars', [], [], $pw)->status);
        $this->assertSame('password_change_required', $this->j($this->call('carol', 'GET', '/api/v1/calendars'))['error']['details']['code']);
        $this->assertSame(200, $this->call('carol', 'GET', '/api/v1/auth/me')->status);
        $this->assertSame(422, $this->call('carol', 'POST', '/api/v1/auth/password', ['current_password' => $pw, 'new_password' => 'faible'])->status);
        $this->assertSame(200, $this->call('carol', 'POST', '/api/v1/auth/password', ['current_password' => $pw, 'new_password' => 'Nouveau-Secret-42'])->status);
        $this->assertSame(200, $this->call('carol', 'GET', '/api/v1/calendars')->status, 'accès rendu après changement');
        $this->assertSame(0, (int) $this->pdo->query("SELECT must_change_password FROM users WHERE username='carol'")->fetchColumn());
    }

    public function testCreateAndUpdateValidation(): void
    {
        $base = ['username' => 'dave', 'email' => 'dave@example.org', 'first_name' => 'Dave', 'last_name' => 'X', 'role' => 'user'];
        $this->assertSame(422, $this->call('admin', 'POST', '/api/v1/users', ['username' => 'ab'] + $base)->status, 'identifiant trop court');
        foreach ([['username' => 'a b c'], ['username' => 'ADMIN'], ['email' => 'alice@example.org'], ['email' => 'ALICE@EXAMPLE.ORG'], ['email' => 'pas-un-mail'], ['email' => ''], ['role' => 'root'], ['role' => ''],
            ['timezone' => 'Mars/Phobos'], ['password' => 'faible'], ['first_name' => ''], ['last_name' => str_repeat('x', 81)]] as $bad) {
            $r = $this->call('admin', 'POST', '/api/v1/users', $bad + $base);
            $this->assertSame(422, $r->status, json_encode($bad) . ' ' . $r->body);
            $this->assertTrue(isset($this->j($r)['error']['details']) && count($this->j($r)['error']['details']) >= 1);
        }
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'aucune création partielle');
        $ok = $this->call('admin', 'POST', '/api/v1/users', $base + ['password' => 'Choisi-Par-Admin-7']);
        $this->assertSame('Choisi-Par-Admin-7', $this->j($ok)['temporary_password']);
        $id = $this->j($ok)['data']['id'];
        $this->assertSame(422, $this->call('admin', 'PUT', "/api/v1/users/$id", ['email' => 'alice@example.org'])->status, 'e-mail déjà pris à la modification');
        $res = $this->call('admin', 'PUT', "/api/v1/users/$id", ['first_name' => 'David', 'timezone' => 'America/Toronto']);
        $up = $this->j($res);
        $this->assertSame(200, $res->status, $res->body);
        $this->assertSame(['David', 'America/Toronto', 'user'], [$up['data']['first_name'], $up['data']['timezone'], $up['data']['role']], 'mise à jour partielle');
        $this->assertSame(404, $this->call('admin', 'GET', '/api/v1/users/9999')->status);
        $this->assertSame(404, $this->call('admin', 'PUT', '/api/v1/users/abc', ['first_name' => 'X'])->status);
    }

    public function testRoleChangeRevokesSessionsAndOwnRoleIsProtected(): void
    {
        $this->call('alice', 'GET', '/api/v1/calendars');
        $this->assertSame(200, $this->call('alice', 'GET', '/api/v1/calendars')->status);
        $r = $this->call('admin', 'PUT', '/api/v1/users/2', ['role' => 'readonly']);
        $this->assertSame('readonly', $this->j($r)['data']['role']);
        $this->assertSame(401, $this->call('alice', 'GET', '/api/v1/calendars')->status, 'sessions révoquées après changement de rôle');
        $this->assertSame(409, $this->call('admin', 'PUT', '/api/v1/users/1', ['role' => 'user'])->status, 'pas de changement de son propre rôle');
        $this->assertSame(200, $this->call('admin', 'PUT', '/api/v1/users/1', ['first_name' => 'Chef'])->status, 'mais son profil reste modifiable');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='user.update' AND details LIKE '%role_to%'")->fetchColumn());
    }

    public function testDeactivateActivateAndLastAdminGuard(): void
    {
        $this->assertSame(409, $this->call('admin', 'POST', '/api/v1/users/1/deactivate')->status, 'pas d\'auto-désactivation');
        $this->assertSame(200, $this->call('alice', 'GET', '/api/v1/calendars')->status);
        $this->assertSame(false, $this->j($this->call('admin', 'POST', '/api/v1/users/2/deactivate'))['data']['is_active']);
        $this->assertSame(401, $this->call('alice', 'GET', '/api/v1/calendars')->status, 'session révoquée');
        $this->expectThrows(fn () => $this->auth->login('alice', 'Correct-Horse-9', '203.0.113.1', 't'), 401, 'connexion refusée');
        $this->assertSame(true, $this->j($this->call('admin', 'POST', '/api/v1/users/2/activate'))['data']['is_active']);
        $this->assertTrue($this->auth->login('alice', 'Correct-Horse-9', '203.0.113.1', 't')['token'] !== '', 'connexion rétablie');
        // dernier administrateur actif : intouchable, même par un autre compte (service appelé directement)
        $svc = new UserAdminService($this->pdo, $this->users, new AuditLogger($this->pdo));
        $other = $this->users->findAuthUser(2);
        $this->expectThrows(fn () => $svc->setActive($other, 1, false, 'x'), 409, 'dernier admin : désactivation');
        $this->expectThrows(fn () => $svc->update($other, 1, ['role' => 'user'], 'x'), 409, 'dernier admin : rétrogradation');
        $this->assertSame(1, (int) $this->pdo->query("SELECT is_active FROM users WHERE id=1")->fetchColumn());
        $b = $this->newUser(['username' => 'boss', 'email' => 'boss@example.org', 'role' => 'admin'])['data']['id'];
        $this->assertSame('user', $this->j($this->call('admin', 'PUT', "/api/v1/users/$b", ['role' => 'user']))['data']['role'], 'avec un autre admin actif c\'est permis');
    }

    public function testResetPasswordRevokesSessionsAndNeverLogsTheSecret(): void
    {
        $this->call('alice', 'GET', '/api/v1/calendars');
        $r = $this->j($this->call('admin', 'POST', '/api/v1/users/2/reset-password'));
        $pw = $r['temporary_password'];
        $this->assertTrue($r['data']['must_change_password'] === true && strlen($pw) === 16);
        $this->assertSame(401, $this->call('alice', 'GET', '/api/v1/calendars')->status, 'sessions révoquées');
        $this->expectThrows(fn () => $this->auth->login('alice', 'Correct-Horse-9', '203.0.113.1', 't'), 401, 'ancien mot de passe refusé');
        $this->assertTrue($this->auth->login('alice', $pw, '203.0.113.1', 't')['token'] !== '', 'nouveau mot de passe accepté');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_logs WHERE details LIKE ' . $this->pdo->quote("%$pw%"))->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='user.password_reset'")->fetchColumn());
        $this->assertSame(422, $this->call('admin', 'POST', '/api/v1/users/2/reset-password', ['password' => 'court'])->status);
        $this->assertSame(422, $this->call('admin', 'POST', '/api/v1/users/2/reset-password', ['password' => ['x']])->status);
        $this->assertSame('Choisi-Par-Admin-7', $this->j($this->call('admin', 'POST', '/api/v1/users/2/reset-password', ['password' => 'Choisi-Par-Admin-7']))['temporary_password']);
    }

    public function testUnlockClearsLoginThrottle(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->expectThrows(fn () => $this->auth->login('alice', 'mauvais', '198.51.100.' . $i, 't'), 401, 'échec');
        }
        $this->expectThrows(fn () => $this->auth->login('alice', 'Correct-Horse-9', '198.51.100.77', 't'), 429, 'compte bloqué');
        $list = $this->j($this->call('admin', 'GET', '/api/v1/users', [], ['q' => 'alice']))['data'];
        $this->assertSame(true, $list[0]['locked']);
        $this->assertSame(false, $this->j($this->call('admin', 'POST', '/api/v1/users/2/unlock'))['data']['locked']);
        $this->assertTrue($this->auth->login('alice', 'Correct-Horse-9', '198.51.100.77', 't')['token'] !== '', 'connexion possible après déverrouillage');
    }

    public function testListingFiltersSortingPagingAndRoles(): void
    {
        $this->newUser();
        $this->newUser(['username' => 'erin', 'email' => 'erin@example.org', 'first_name' => 'Erin', 'role' => 'readonly']);
        $this->call('admin', 'POST', '/api/v1/users/2/deactivate');
        $get = fn (array $q) => $this->j($this->call('admin', 'GET', '/api/v1/users', [], $q));
        $this->assertSame(4, $get([])['meta']['total']);
        $this->assertSame(['carol'], array_column($get(['q' => 'Carol D'])['data'], 'username'), 'recherche prénom + nom');
        $this->assertSame(0, $get(['q' => '%'])['meta']['total'], 'jokers LIKE échappés');
        $this->assertSame(0, $get(['q' => "x' OR '1'='1"])['meta']['total']);
        $this->assertSame(['erin'], array_column($get(['role' => 'readonly'])['data'], 'username'));
        $this->assertSame(['alice'], array_column($get(['active' => '0'])['data'], 'username'));
        $p = $get(['per_page' => 2, 'page' => 2, 'sort' => 'username']);
        $this->assertSame(['carol', 'erin'], array_column($p['data'], 'username'));
        $this->assertSame(2, $p['meta']['pages']);
        $this->assertSame(['erin', 'carol', 'alice', 'admin'], array_column($get(['sort' => 'username', 'dir' => 'desc'])['data'], 'username'));
        $this->assertSame(422, $this->call('admin', 'GET', '/api/v1/users', [], ['sort' => 'password_hash'])->status);
        $this->assertSame(422, $this->call('admin', 'GET', '/api/v1/users', [], ['active' => 'x'])->status);
        $roles = $this->j($this->call('admin', 'GET', '/api/v1/roles'))['data'];
        $this->assertSame(['admin', 'manager', 'user', 'readonly'], array_column($roles, 'code'));
        $this->assertSame([1, 0, 2, 1], array_column($roles, 'users'));
        $this->assertTrue(in_array('users.manage', $roles[0]['permissions'], true) && !in_array('users.manage', $roles[2]['permissions'], true));
        $this->assertTrue(!str_contains($this->call('admin', 'GET', '/api/v1/users')->body, 'argon'), 'aucun hash exposé');
    }

    private function expectThrows(callable $f, int $status, string $msg): void
    {
        try {
            $f();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status, $msg);
            return;
        }
        $this->assertTrue(false, "exception HTTP $status attendue : $msg");
    }
}
