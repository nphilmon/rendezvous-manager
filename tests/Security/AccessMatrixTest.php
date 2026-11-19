<?php
declare(strict_types=1);

namespace Tests\Security;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use Tests\DatabaseTestCase;

/**
 * Inventaire automatique des routes de l'API : aucune route ajoutée plus tard ne peut rester accessible sans authentification ni sans
 * jeton CSRF, et les droits des quatre rôles sont figés dans une matrice explicite.
 */
final class AccessMatrixTest extends DatabaseTestCase
{
    /** Seules routes accessibles sans session (supervision : aucun détail interne dans leur réponse, vérifié ci-dessous). */
    private const PUBLIC = ['POST /api/v1/auth/login', 'GET /api/v1/health', 'GET /api/v1/version'];
    private Router $router;
    private AuthService $auth;
    /** @var array<string,array{cookie:string,csrf:string}> */
    private array $sessions = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->sessions = [];
        $users = new UserRepository($this->pdo);
        $this->auth = new AuthService($this->pdo, $users, new AuditLogger($this->pdo));
        $this->router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($this->router, $this->pdo);
        foreach (['admin' => 'admin', 'manager' => 'manager', 'user' => 'user', 'readonly' => 'readonly'] as $n => $role) {
            $users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => ucfirst($n), 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $users->create(['role_code' => 'user', 'username' => 'target', 'email' => 'target@example.org', 'first_name' => 'Target', 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (3, 'Perso')");
    }

    private function session(string $as): array
    {
        return $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
    }

    private function call(?string $as, string $method, string $path, array $body = [], bool $csrf = true): Response
    {
        $h = [];
        if ($as !== null) {
            $s = $this->session($as);
            $h['cookie'] = $s['cookie'];
            if ($csrf && $method !== 'GET') {
                $h['x-csrf-token'] = $s['csrf'];
            }
        }
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
        }
        return Application::handle(new Request($method, $path, $query, $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
    }

    /** @return list<array{string,string}> routes d'API avec paramètres remplacés par un identifiant */
    private function apiRoutes(): array
    {
        $out = [];
        foreach ($this->router->routes() as [$m, $p]) {
            if (str_starts_with($p, '/api/')) {
                $out[] = [$m, preg_replace('#\{\w+\}#', '1', $p)];
            }
        }
        return $out;
    }

    public function testEveryApiRouteRequiresAuthentication(): void
    {
        $routes = $this->apiRoutes();
        $this->assertTrue(count($routes) >= 70, 'inventaire complet (' . count($routes) . ' routes)');
        $open = [];
        foreach ($routes as [$m, $p]) {
            if (in_array("$m " . $p, self::PUBLIC, true)) {
                continue;
            }
            $st = $this->call(null, $m, $p . ($m === 'GET' && str_contains($p, '/export/') ? '?format=csv' : ''))->status;
            if ($st !== 401) {
                $open[] = "$m $p → $st";
            }
        }
        $this->assertSame([], $open, 'routes accessibles sans session : ' . implode(' ; ', $open));
    }

    public function testPublicEndpointsExposeNothingBeyondStatus(): void
    {
        $v = json_decode($this->call(null, 'GET', '/api/v1/version')->body, true);
        $this->assertSame(['name', 'version'], array_keys($v));
        $h = $this->call(null, 'GET', '/api/v1/health');
        $this->assertTrue(in_array($h->status, [200, 503], true));
        $this->assertSame(['status', 'database'], array_keys(json_decode($h->body, true)), 'ni hôte, ni utilisateur, ni message SQL');
    }

    public function testEveryUnsafeRouteRequiresACsrfToken(): void
    {
        $missing = [];
        foreach ($this->apiRoutes() as [$m, $p]) {
            if ($m === 'GET' || in_array("$m $p", self::PUBLIC, true)) {
                continue;
            }
            $st = $this->call('admin', $m, $p, [], false)->status;
            if ($st !== 403) {
                $missing[] = "$m $p → $st";
            }
            $bad = Application::handle(new Request($m, $p, [], '', ['cookie' => $this->session('admin')['cookie'], 'x-csrf-token' => str_repeat('0', 64)], '203.0.113.1'), $this->router);
            if ($bad->status !== 403) {
                $missing[] = "$m $p (jeton faux) → {$bad->status}";
            }
        }
        $this->assertSame([], $missing, 'routes sans contrôle CSRF : ' . implode(' ; ', $missing));
        // l'en-tête Origin d'un autre site est refusé même avec un bon jeton
        $s = $this->session('admin');
        $r = Application::handle(new Request('POST', '/api/v1/notifications/read-all', [], '', ['cookie' => $s['cookie'], 'x-csrf-token' => $s['csrf'], 'origin' => 'https://evil.example', 'host' => 'app.example.org'], '203.0.113.1'), $this->router);
        $this->assertSame(403, $r->status, 'Origin étranger');
    }

    public function testRoleMatrix(): void
    {
        // [méthode chemin, corps] => [admin, manager, user, readonly] ; « ok » = ni 401 ni 403 (la validation peut répondre 422/404)
        $matrix = [
            ['GET /api/v1/dashboard', [], ['ok', 'ok', 'ok', 'ok']],
            ['GET /api/v1/appointments', [], ['ok', 'ok', 'ok', 'ok']],
            ['POST /api/v1/appointments', [], ['ok', 'ok', 'ok', 403]],
            ['POST /api/v1/calendars', [], ['ok', 'ok', 'ok', 403]],
            ['POST /api/v1/contacts', [], ['ok', 'ok', 'ok', 403]],
            ['POST /api/v1/categories', [], ['ok', 'ok', 403, 403]],
            ['GET /api/v1/export/appointments?format=csv', [], ['ok', 'ok', 'ok', 403]],
            ['POST /api/v1/import/contacts', [], ['ok', 'ok', 'ok', 403]],
            ['POST /api/v1/import/appointments', [], ['ok', 'ok', 'ok', 403]],
            ['GET /api/v1/audit', [], ['ok', 'ok', 403, 403]],
            ['GET /api/v1/users', [], ['ok', 403, 403, 403]],
            ['POST /api/v1/users', [], ['ok', 403, 403, 403]],
            ['GET /api/v1/roles', [], ['ok', 403, 403, 403]],
            ['POST /api/v1/users/5/reset-password', [], ['ok', 403, 403, 403]],
            ['GET /api/v1/backups', [], ['ok', 403, 403, 403]],
            ['POST /api/v1/backups/x/verify', [], ['ok', 403, 403, 403]],
            ['DELETE /api/v1/backups/x', [], ['ok', 403, 403, 403]],
            ['GET /api/v1/settings', [], ['ok', 'ok', 'ok', 'ok']],
            ['GET /api/v1/settings/global', [], ['ok', 403, 403, 403]],
            ['PUT /api/v1/settings/global', [], ['ok', 403, 403, 403]],
            ['GET /api/v1/notifications', [], ['ok', 'ok', 'ok', 'ok']],
        ];
        $wrong = [];
        foreach ($matrix as [$route, $body, $expect]) {
            [$m, $p] = explode(' ', $route, 2);
            foreach (['admin', 'manager', 'user', 'readonly'] as $i => $role) {
                $st = $this->call($role, $m, $p, $body)->status;
                $good = $expect[$i] === 'ok' ? !in_array($st, [401, 403], true) : $st === $expect[$i];
                if (!$good) {
                    $wrong[] = "$route [$role] attendu {$expect[$i]}, obtenu $st";
                }
            }
        }
        $this->assertSame([], $wrong, implode(' ; ', $wrong));
    }

    public function testErrorResponsesNeverLeakInternals(): void
    {
        $bodies = [];
        foreach ([['GET', '/api/v1/nope'], ['DELETE', '/api/v1/auth/me'], ['GET', '/api/v1/appointments/abc'], ['GET', '/api/v1/appointments/99999999999999999999'], ['POST', '/api/v1/appointments']] as [$m, $p]) {
            $r = $this->call('user', $m, $p, $m === 'POST' ? ['title' => str_repeat('x', 10)] : []);
            $this->assertTrue($r->status >= 400 && $r->status < 500, "$m $p → {$r->status}");
            $this->assertTrue(str_starts_with($r->headers['Content-Type'], 'application/json'), 'erreur JSON');
            $bodies[] = $r->body;
        }
        foreach ($bodies as $b) {
            $this->assertTrue(!preg_match('#/home/|\.php|SQLSTATE|Stack trace|PDOException#i', $b), 'aucun chemin, trace ni message SQL : ' . $b);
        }
    }
}
