<?php
declare(strict_types=1);

namespace Tests\Security;

use App\Controllers\AuthController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\Authenticator;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\PermissionService;
use Tests\DatabaseTestCase;

final class AuthTest extends DatabaseTestCase
{
    private const PW = 'Correct-Horse-9';
    private Router $router;
    private UserRepository $users;

    public function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $svc = new AuthService($this->pdo, $this->users, new AuditLogger($this->pdo));
        $c = new AuthController($svc, new Authenticator($svc));
        $this->router = new Router();
        $this->router->add('POST', '/api/v1/auth/login', fn ($q) => $c->login($q));
        $this->router->add('POST', '/api/v1/auth/logout', fn ($q) => $c->logout($q));
        $this->router->add('GET', '/api/v1/auth/me', fn ($q) => $c->me($q));
        $this->router->add('POST', '/api/v1/auth/password', fn ($q) => $c->changePassword($q));
    }

    private function user(string $name, string $role = 'user'): int
    {
        return $this->users->create(['role_code' => $role, 'username' => $name, 'email' => "$name@example.org",
            'first_name' => 'F', 'last_name' => 'L', 'password_hash' => PasswordHasher::hash(self::PW)]);
    }

    /** @param array<string,string> $h */
    private function call(string $m, string $path, array $body = [], array $h = []): Response
    {
        return Application::handle(new Request($m, $path, [], $body ? json_encode($body) : '', $h, '203.0.113.5'), $this->router);
    }

    /** @return array{cookie:string,csrf:string} */
    private function login(string $name): array
    {
        $r = $this->call('POST', '/api/v1/auth/login', ['identifier' => $name, 'password' => self::PW]);
        $this->assertSame(200, $r->status);
        preg_match('/^(rvm_session=[0-9a-f]{64})/', $r->headers['Set-Cookie'], $m);
        return ['cookie' => $m[1], 'csrf' => json_decode($r->body, true)['csrf_token']];
    }

    public function testLoginMeLogout(): void
    {
        $this->user('alice');
        $s = $this->login('alice');
        $me = $this->call('GET', '/api/v1/auth/me', [], ['cookie' => $s['cookie']]);
        $this->assertSame(200, $me->status);
        $this->assertSame('alice', json_decode($me->body, true)['user']['username']);
        $this->assertTrue(!str_contains($me->body, 'password'), 'hash exposé');
        $out = $this->call('POST', '/api/v1/auth/logout', [], ['cookie' => $s['cookie'], 'x-csrf-token' => $s['csrf']]);
        $this->assertSame(200, $out->status);
        $this->assertSame(401, $this->call('GET', '/api/v1/auth/me', [], ['cookie' => $s['cookie']])->status);
    }

    public function testCookieFlagsAndTokenHashedInDb(): void
    {
        $this->user('alice');
        $r = $this->call('POST', '/api/v1/auth/login', ['identifier' => 'alice@example.org', 'password' => self::PW]);
        $c = $r->headers['Set-Cookie'];
        foreach (['HttpOnly', 'SameSite=Lax', 'Secure'] as $flag) {
            $this->assertTrue(str_contains($c, $flag), "drapeau manquant : $flag");
        }
        preg_match('/rvm_session=([0-9a-f]{64})/', $c, $m);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM user_sessions WHERE id = '{$m[1]}'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM user_sessions WHERE id = '" . hash('sha256', $m[1]) . "'")->fetchColumn());
    }

    public function testPasswordStoredWithArgon2idWhenAvailable(): void
    {
        $id = $this->user('alice');
        $h = $this->users->passwordHash($id);
        $this->assertTrue(str_starts_with($h, defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$'));
        $this->assertTrue(!str_contains($h, self::PW));
    }

    public function testBadLoginIsGenericForUnknownAndWrongPassword(): void
    {
        $this->user('alice');
        $a = $this->call('POST', '/api/v1/auth/login', ['identifier' => 'alice', 'password' => 'wrong']);
        $b = $this->call('POST', '/api/v1/auth/login', ['identifier' => 'ghost', 'password' => 'wrong']);
        $this->assertSame(401, $a->status);
        $this->assertSame($a->body, $b->body);
    }

    public function testBruteForceThrottlingAndIsolation(): void
    {
        $this->user('alice');
        $this->user('bob');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->call('POST', '/api/v1/auth/login', ['identifier' => 'alice', 'password' => 'x'])->status);
        }
        $r = $this->call('POST', '/api/v1/auth/login', ['identifier' => 'alice', 'password' => self::PW]);
        $this->assertSame(429, $r->status, 'même le bon mot de passe est refusé pendant le blocage');
        $this->assertTrue(isset($r->headers['Retry-After']));
        $this->assertSame(200, $this->call('POST', '/api/v1/auth/login', ['identifier' => 'bob', 'password' => self::PW])->status);
    }

    public function testCsrfRequiredOnUnsafeMethods(): void
    {
        $this->user('alice');
        $s = $this->login('alice');
        $this->assertSame(403, $this->call('POST', '/api/v1/auth/logout', [], ['cookie' => $s['cookie']])->status);
        $this->assertSame(403, $this->call('POST', '/api/v1/auth/logout', [], ['cookie' => $s['cookie'], 'x-csrf-token' => 'bad'])->status);
        $this->assertSame(403, $this->call('POST', '/api/v1/auth/logout', [], ['cookie' => $s['cookie'], 'x-csrf-token' => $s['csrf'], 'origin' => 'https://evil.example', 'host' => 'app.example.org'])->status);
        $this->assertSame(200, $this->call('POST', '/api/v1/auth/logout', [], ['cookie' => $s['cookie'], 'x-csrf-token' => $s['csrf'], 'origin' => 'https://app.example.org', 'host' => 'app.example.org'])->status);
    }

    public function testSessionExpiryIdleAndDeactivation(): void
    {
        $id = $this->user('alice');
        $s = $this->login('alice');
        $this->pdo->exec("UPDATE user_sessions SET last_seen_at = '2000-01-01 00:00:00'");
        $this->assertSame(401, $this->call('GET', '/api/v1/auth/me', [], ['cookie' => $s['cookie']])->status, 'inactivité');
        $s = $this->login('alice');
        $this->pdo->exec("UPDATE user_sessions SET expires_at = '2000-01-01 00:00:00'");
        $this->assertSame(401, $this->call('GET', '/api/v1/auth/me', [], ['cookie' => $s['cookie']])->status, 'expiration');
        $s = $this->login('alice');
        $this->pdo->exec("UPDATE users SET is_active = 0 WHERE id = $id");
        $this->assertSame(401, $this->call('GET', '/api/v1/auth/me', [], ['cookie' => $s['cookie']])->status, 'compte désactivé');
        $this->assertSame(401, $this->call('POST', '/api/v1/auth/login', ['identifier' => 'alice', 'password' => self::PW])->status);
    }

    public function testChangePasswordRevokesOtherSessions(): void
    {
        $this->user('alice');
        $s1 = $this->login('alice');
        $s2 = $this->login('alice');
        $h = ['cookie' => $s1['cookie'], 'x-csrf-token' => $s1['csrf']];
        $this->assertSame(403, $this->call('POST', '/api/v1/auth/password', ['current_password' => 'no', 'new_password' => 'New-Passw0rd-xyz'], $h)->status);
        $this->assertSame(422, $this->call('POST', '/api/v1/auth/password', ['current_password' => self::PW, 'new_password' => 'faible'], $h)->status);
        $this->assertSame(200, $this->call('POST', '/api/v1/auth/password', ['current_password' => self::PW, 'new_password' => 'New-Passw0rd-xyz'], $h)->status);
        $this->assertSame(200, $this->call('GET', '/api/v1/auth/me', [], ['cookie' => $s1['cookie']])->status);
        $this->assertSame(401, $this->call('GET', '/api/v1/auth/me', [], ['cookie' => $s2['cookie']])->status);
    }

    public function testAuditHasNoSecrets(): void
    {
        $this->user('alice');
        $this->call('POST', '/api/v1/auth/login', ['identifier' => 'alice', 'password' => 'SuperSecretWrong1']);
        $this->login('alice');
        $all = json_encode($this->pdo->query('SELECT * FROM audit_logs')->fetchAll());
        $this->assertTrue(str_contains($all, 'auth.login_failed') && str_contains($all, 'auth.login'));
        $this->assertTrue(!str_contains($all, 'SuperSecretWrong1') && !str_contains($all, self::PW));
    }

    public function testRolePermissions(): void
    {
        $this->user('adm', 'admin');
        $this->user('ro', 'readonly');
        $ro = $this->users->findAuthUser(2);
        $this->assertTrue($ro->can('appointments.read') && !$ro->can('appointments.write'));
        $this->assertTrue($this->users->findAuthUser(1)->can('users.manage'));
    }

    public function testCalendarPermissions(): void
    {
        $owner = $this->user('owner');
        $other = $this->user('other');
        $ro = $this->user('ro', 'readonly');
        $mgr = $this->user('mgr', 'manager');
        $this->pdo->exec("INSERT INTO calendars (owner_id, name, visibility) VALUES ($owner,'priv','private'), ($owner,'org','organization')");
        $svc = new PermissionService($this->pdo);
        $u = fn (int $id) => $this->users->findAuthUser($id);

        $this->assertTrue($svc->canAccessCalendar($u($owner), 1, 'delete'));
        $this->assertTrue(!$svc->canAccessCalendar($u($other), 1, 'read'), 'calendrier privé');
        $this->assertTrue($svc->canAccessCalendar($u($other), 2, 'read'), 'organisation = lecture');
        $this->assertTrue(!$svc->canAccessCalendar($u($other), 2, 'update'));
        $this->assertTrue($svc->canAccessCalendar($u($mgr), 1, 'update'), 'manage_all');

        $this->pdo->exec("INSERT INTO calendar_permissions (calendar_id, user_id, can_read, can_create) VALUES (1,$other,1,1)");
        $this->assertTrue($svc->canAccessCalendar($u($other), 1, 'read'));
        $this->assertTrue($svc->canAccessCalendar($u($other), 1, 'create'));
        $this->assertTrue(!$svc->canAccessCalendar($u($other), 1, 'delete'));

        $this->pdo->exec("INSERT INTO calendar_permissions (calendar_id, user_id, can_read, can_create, can_update, can_delete, can_share) VALUES (1,$ro,1,1,1,1,1)");
        $this->assertTrue($svc->canAccessCalendar($u($ro), 1, 'read'));
        $this->assertTrue(!$svc->canAccessCalendar($u($ro), 1, 'create'), 'lecture seule plafonne les droits');
        $this->assertTrue(!$svc->canAccessCalendar($u($ro), 1, 'share'));
        $this->assertTrue(!$svc->canAccessCalendar($u($other), 999, 'read'), 'calendrier inexistant');

        $this->assertSame([1, 2], $svc->readableCalendarIds($u($other)));
        $this->pdo->exec('UPDATE calendars SET deleted_at = NOW() WHERE id = 2');
        $this->assertSame([1], $svc->readableCalendarIds($u($other)));
    }
}
