<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\PageController;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\StatsRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Navigation;
use App\Services\PermissionService;
use Tests\DatabaseTestCase;

final class PageTest extends DatabaseTestCase
{
    private PageController $pages;
    private AuthService $auth;
    private UserRepository $users;

    public function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->auth = new AuthService($this->pdo, $this->users, new AuditLogger($this->pdo));
        $this->pages = new PageController($this->auth, new PermissionService($this->pdo), new StatsRepository($this->pdo));
    }

    private function user(string $name, string $role, string $first = 'Jean'): int
    {
        return $this->users->create(['role_code' => $role, 'username' => $name, 'email' => "$name@example.org", 'first_name' => $first,
            'last_name' => 'Test', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
    }

    private function cookie(string $name): string
    {
        return 'rvm_session=' . $this->auth->login($name, 'Correct-Horse-9', '203.0.113.1', 'test')['token'];
    }

    private function get(string $method, string $path, ?string $cookie = null, array $query = []): Response
    {
        $req = new Request('GET', $path, $query, '', $cookie ? ['cookie' => $cookie] : []);
        return $this->pages->$method($req);
    }

    public function testAnonymousIsRedirectedToLoginWithSafeNext(): void
    {
        $r = $this->get('calendar', '/calendar', null, ['view' => 'month']);
        $this->assertSame(302, $r->status);
        $this->assertSame('/login?next=' . rawurlencode('/calendar?view=month'), $r->headers['Location']);
    }

    public function testSafeNextBlocksOpenRedirects(): void
    {
        foreach (['//evil.example', 'https://evil.example', '/\\evil', "/x\ny", '/api/v1/auth/me', '/install', '', 'javascript:alert(1)'] as $bad) {
            $this->assertSame('/', PageController::safeNext($bad), $bad);
        }
        $this->assertSame('/calendar?view=week', PageController::safeNext('/calendar?view=week'));
    }

    public function testLoginPageAndRedirectWhenAuthenticated(): void
    {
        $this->user('alice', 'user');
        $r = $this->get('login', '/login');
        $this->assertSame(200, $r->status);
        $this->assertTrue(str_contains($r->body, 'id="login-form"'));
        $c = $this->cookie('alice');
        $this->assertSame('/calendar', $this->get('login', '/login', $c, ['next' => '/calendar'])->headers['Location']);
        $this->assertSame('/', $this->get('login', '/login', $c, ['next' => '//evil.example'])->headers['Location']);
    }

    public function testSecurityHeadersAndNoInlineCode(): void
    {
        $this->user('alice', 'user');
        foreach ([$this->get('login', '/login'), $this->get('dashboard', '/', $this->cookie('alice')), $this->get('settings', '/settings', $this->cookie('alice'))] as $r) {
            $csp = $r->headers['Content-Security-Policy'];
            $this->assertTrue(str_contains($csp, "script-src 'self'") && !str_contains($csp, 'unsafe-inline') && str_contains($csp, "frame-ancestors 'none'"));
            $this->assertTrue(!preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $r->body), 'script inline');
            $this->assertTrue(!preg_match('/\sstyle="/i', $r->body), 'attribut style inline');
            $this->assertTrue(!preg_match('/\son(click|load|error)=/i', $r->body), 'gestionnaire inline');
            $this->assertSame('no-store', $r->headers['Cache-Control']);
        }
    }

    public function testShellShowsUserCsrfAndEscapes(): void
    {
        $this->user('alice', 'user', '<img src=x onerror=alert(1)>');
        $body = $this->get('dashboard', '/', $this->cookie('alice'))->body;
        $this->assertTrue(!str_contains($body, '<img src=x'), 'XSS non échappé');
        $this->assertTrue(str_contains($body, 'meta name="csrf-token"'));
        $this->assertTrue(str_contains($body, 'id="mini-cal"') && str_contains($body, 'Nouveau rendez-vous'));
    }

    public function testNavigationFollowsPermissions(): void
    {
        $this->user('admin', 'admin');
        $this->user('ro', 'readonly');
        $adminNav = array_keys(Navigation::visibleTo($this->users->findAuthUser(1)));
        $roNav = array_keys(Navigation::visibleTo($this->users->findAuthUser(2)));
        $this->assertTrue(in_array('users', $adminNav, true) && in_array('reports', $adminNav, true));
        $this->assertTrue(!in_array('users', $roNav, true) && !in_array('reports', $roNav, true));
        $this->assertTrue(!str_contains($this->get('dashboard', '/', $this->cookie('ro'))->body, 'Nouveau rendez-vous'), 'lecture seule ne crée pas');
    }

    public function testForbiddenModuleForReadonly(): void
    {
        $this->user('ro', 'readonly');
        $r = $this->pages->module(new Request('GET', '/users', [], '', ['cookie' => $this->cookie('ro')]), 'users', 'Utilisateurs', 16, 'users.manage');
        $this->assertSame(403, $r->status);
        $this->assertTrue(str_contains($r->body, 'pas la permission'));
    }

    public function testEveryNavigationLinkHasAWorkingPage(): void
    {
        $this->user('admin', 'admin');
        $c = $this->cookie('admin');
        foreach (Navigation::items() as $key => $item) {
            $req = new Request('GET', $item['href'], [], '', ['cookie' => $c]);
            $r = match (true) {
                $key === 'dashboard' => $this->pages->dashboard($req),
                $key === 'calendar' => $this->pages->calendar($req),
                $key === 'settings' => $this->pages->settings($req),
                in_array($key, ['all', 'mine', 'today', 'upcoming'], true) => $this->pages->appointments($req, $key),
                default => $this->pages->module($req, $key, $item['label'], $item['phase']),
            };
            $this->assertSame(200, $r->status, $item['href']);
            $this->assertTrue(str_contains($r->body, 'aria-current="page"'), "élément actif manquant : $key");
        }
        $this->assertSame(404, $this->pages->notFound(new Request('GET', '/nope', [], '', ['cookie' => $c]))->status);
        $this->assertSame(404, $this->pages->notFound(new Request('GET', '/nope'))->status);
    }

    public function testCalendarViewAndDateAreSanitized(): void
    {
        $this->user('alice', 'user');
        $b = $this->get('calendar', '/calendar', $this->cookie('alice'), ['view' => '"><script>', 'date' => '2026-02-31'])->body;
        $this->assertTrue(str_contains($b, 'data-view="week"') && !str_contains($b, '<script>'));
        $this->assertTrue(!str_contains($b, 'data-date="2026-02-31"'), 'date invalide acceptée');
        $ok = $this->get('calendar', '/calendar', $this->cookie('alice'), ['view' => 'month', 'date' => '2026-11-18'])->body;
        $this->assertTrue(str_contains($ok, 'data-view="month"') && str_contains($ok, 'data-date="2026-11-18"'));
    }

    public function testDashboardCountsAreRealAndPermissionScoped(): void
    {
        $a = $this->user('alice', 'user');
        $b = $this->user('bob', 'user');
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES ($a,'A'), ($b,'B')");
        $ins = $this->pdo->prepare("INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc, status, deleted_at) VALUES (UUID(),?,?,?,?,?,?,?)");
        $now = new \DateTimeImmutable('2026-11-18 10:00:00', new \DateTimeZone('UTC')); // mercredi
        $rows = [
            [1, $a, 'aujourd\'hui', '2026-11-18 14:00:00', '2026-11-18 15:00:00', 'scheduled', null],
            [1, $a, 'lundi (semaine)', '2026-11-16 08:00:00', '2026-11-16 09:00:00', 'completed', null],
            [1, $a, 'semaine suivante', '2026-11-25 08:00:00', '2026-11-25 09:00:00', 'scheduled', null],
            [1, $a, 'annulé futur', '2026-11-26 08:00:00', '2026-11-26 09:00:00', 'cancelled', null],
            [1, $a, 'supprimé', '2026-11-18 16:00:00', '2026-11-18 17:00:00', 'scheduled', '2026-11-01 00:00:00'],
            [2, $b, 'autre calendrier', '2026-11-18 14:00:00', '2026-11-18 15:00:00', 'scheduled', null],
        ];
        foreach ($rows as $r) {
            $ins->execute($r);
        }
        $stats = new StatsRepository($this->pdo);
        $perms = new PermissionService($this->pdo);
        $c = $stats->counts($perms->readableCalendarIds($this->users->findAuthUser($a)), 'Europe/Paris', $now);
        $this->assertSame(['total' => 4, 'today' => 1, 'week' => 2, 'upcoming' => 2], $c);
        $this->assertSame(['total' => 0, 'today' => 0, 'week' => 0, 'upcoming' => 0], $stats->counts([], 'UTC', $now));
        // fuseau : 23:30 UTC le 17 est déjà le 18 à Paris
        $this->pdo->exec("UPDATE appointments SET start_utc='2026-11-17 23:30:00', end_utc='2026-11-17 23:45:00' WHERE title='lundi (semaine)'");
        $paris = $stats->counts([1], 'Europe/Paris', $now);
        $this->assertSame(2, $paris['today'], 'bornes calculées dans le fuseau utilisateur');
    }

    public function testUnreadBadgeCountsOnlyOwnInappNotifications(): void
    {
        $a = $this->user('alice', 'user');
        $b = $this->user('bob', 'user');
        $ins = $this->pdo->prepare("INSERT INTO notification_deliveries (user_id, channel, occurrence_start_utc, scheduled_at, status, title, read_at) VALUES (?,?,?,?,?,?,?)");
        $t = '2026-11-18 10:00:00';
        $ins->execute([$a, 'inapp', $t, $t, 'sent', 'n1', null]);
        $ins->execute([$a, 'inapp', $t, $t, 'sent', 'n2', $t]);
        $ins->execute([$a, 'email', $t, $t, 'sent', 'n3', null]);
        $ins->execute([$b, 'inapp', $t, $t, 'sent', 'n4', null]);
        $this->assertSame(1, (new StatsRepository($this->pdo))->unreadNotifications($a));
        $this->assertTrue(str_contains($this->get('dashboard', '/', $this->cookie('alice'))->body, '1 non lues'));
    }

    public function testStaticAssetsExistAndAreReferenced(): void
    {
        $root = dirname(__DIR__, 2) . '/public';
        foreach (['assets/css/app.css', 'assets/css/calendar.css', 'assets/js/app.js', 'assets/js/theme.js', 'assets/js/calendar.js', 'assets/icons/logo.svg', 'manifest.webmanifest'] as $f) {
            $this->assertTrue(is_file("$root/$f"), "manquant : $f");
        }
        $this->assertTrue(json_decode((string) file_get_contents("$root/manifest.webmanifest"), true) !== null, 'manifest JSON invalide');
        $this->assertTrue(!str_contains((string) file_get_contents("$root/assets/css/app.css"), '@import'), 'aucune ressource externe');
    }
}
