<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\PageController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\SettingsRepository;
use App\Repositories\StatsRepository;
use App\Repositories\SyncRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\PermissionService;
use Tests\DatabaseTestCase;

final class SettingsUiTest extends DatabaseTestCase
{
    private Router $router;
    private AuthService $auth;
    private PageController $pages;
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
        $this->pages = new PageController($this->auth, new PermissionService($this->pdo), new StatsRepository($this->pdo), new SyncRepository($this->pdo), new SettingsRepository($this->pdo));
        foreach (['admin' => 'admin', 'alice' => 'user', 'bob' => 'user'] as $n => $role) {
            $users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => ucfirst($n), 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (2,'Perso'), (3,'Calendrier de Bob')");
    }

    private function call(string $as, string $method, string $path, array $body = []): Response
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
        $h = ['cookie' => $this->sessions[$as]['cookie']];
        if ($method !== 'GET') {
            $h['x-csrf-token'] = $this->sessions[$as]['csrf'];
        }
        return Application::handle(new Request($method, $path, [], $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
    }

    private function j(Response $r): array
    {
        return json_decode($r->body, true) ?? [];
    }

    private function page(string $as, string $method, array $query = []): string
    {
        $this->call($as, 'GET', '/api/v1/auth/me');
        return $this->pages->$method(new Request('GET', '/x', $query, '', ['cookie' => $this->sessions[$as]['cookie']]))->body;
    }

    public function testDefaultsThenPersistedPreferences(): void
    {
        $g = $this->j($this->call('alice', 'GET', '/api/v1/settings'));
        $this->assertSame(SettingsRepository::UI_DEFAULTS, $g['ui']);
        $this->assertSame(false, $g['meta']['ui_stored']);
        $this->assertSame(['first_name' => 'Alice', 'last_name' => 'T', 'timezone' => 'Europe/Paris', 'email' => 'alice@example.org', 'username' => 'alice'], $g['profile']);
        $r = $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['theme' => 'dark', 'view' => 'month', 'week_start' => 0, 'start_hour' => 8, 'end_hour' => 19, 'step' => 15, 'weekend' => false, 'hour12' => true, 'default_duration' => 45, 'default_calendar' => 1, 'hidden_calendars' => [2, 1, 2]]]);
        $this->assertSame(200, $r->status, $r->body);
        $ui = $this->j($r)['ui'];
        $this->assertSame(['dark', 'month', 0, 8, 19, 15, false, true, 'fr', 'auto', 45, 1, [2, 1]], array_values($ui), 'doublons retirés dans la liste des calendriers masqués');
        $this->assertSame(true, $this->j($r)['meta']['ui_stored']);
        $this->assertSame($ui, $this->j($this->call('alice', 'GET', '/api/v1/settings'))['ui'], 'relu depuis la base');
        $this->assertSame(SettingsRepository::UI_DEFAULTS, $this->j($this->call('bob', 'GET', '/api/v1/settings'))['ui'], 'propres à chaque utilisateur');
        $p = $this->j($this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['view' => 'day']]))['ui'];
        $this->assertSame(['day', 'dark', 8], [$p['view'], $p['theme'], $p['start_hour']], 'mise à jour partielle');
    }

    public function testValidationIsStrictAndAtomic(): void
    {
        $bad = [['theme' => 'rose'], ['view' => 'year'], ['week_start' => 2], ['week_start' => '1'], ['start_hour' => -1], ['start_hour' => 24], ['end_hour' => 0], ['end_hour' => 25], ['step' => 7], ['step' => '30'],
            ['weekend' => 'oui'], ['hour12' => 1], ['default_duration' => 4], ['default_duration' => 721], ['default_duration' => 'x'], ['colour' => 'red'], ['language' => 'de'], ['language' => 1], ['date_format' => 'dd/mm'], ['date_format' => null],
            ['start_hour' => 10, 'end_hour' => 10], ['hidden_calendars' => 'x'], ['hidden_calendars' => [0]], ['hidden_calendars' => [1.5]], ['hidden_calendars' => ['1']], ['hidden_calendars' => ['a' => 1]], ['hidden_calendars' => range(1, 201)], ['default_calendar' => 2], ['default_calendar' => 999], ['default_calendar' => 'a']];
        foreach ($bad as $b) {
            $r = $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => $b]);
            $this->assertSame(422, $r->status, json_encode($b) . ' ' . $r->body);
        }
        // début ≥ fin se contrôle aussi contre la valeur déjà enregistrée
        $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['start_hour' => 8, 'end_hour' => 18]]);
        $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['end_hour' => 8]])->status);
        $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['start_hour' => 20]])->status);
        // atomique : un groupe invalide n'enregistre rien, même si l'autre groupe est valide
        $r = $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['theme' => 'light'], 'notifications' => ['privacy' => 'nope']]);
        $this->assertSame(422, $r->status);
        $this->assertSame('auto', $this->j($this->call('alice', 'GET', '/api/v1/settings'))['ui']['theme']);
        foreach ([[], ['nothing' => 1], ['ui' => 'dark'], ['ui' => ['theme' => 'dark'], 'extra' => 1]] as $b) {
            $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/settings', $b)->status, json_encode($b));
        }
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM settings WHERE user_id = 2 AND setting_key = 'ui.theme' AND setting_value = '\"rose\"'")->fetchColumn());
    }

    public function testLanguageAndDateFormatArePersistedPerUser(): void
    {
        $g = $this->j($this->call('alice', 'GET', '/api/v1/settings'))['ui'];
        $this->assertSame(['fr', 'auto'], [$g['language'], $g['date_format']], 'valeurs par défaut');
        $r = $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['language' => 'en', 'date_format' => 'mdy']]);
        $this->assertSame(200, $r->status, $r->body);
        $a = $this->j($this->call('alice', 'GET', '/api/v1/settings'))['ui'];
        $this->assertSame(['en', 'mdy'], [$a['language'], $a['date_format']]);
        $b = $this->j($this->call('bob', 'GET', '/api/v1/settings'))['ui'];
        $this->assertSame(['fr', 'auto'], [$b['language'], $b['date_format']], 'propres à chaque utilisateur');
    }

    public function testDefaultCalendarMustBeCreatableByTheUser(): void
    {
        $this->assertSame(200, $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['default_calendar' => 1]])->status);
        $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['default_calendar' => 2]])->status, 'calendrier d\'un autre sans droit de création');
        $this->pdo->exec('INSERT INTO calendar_permissions (calendar_id, user_id, can_read, can_create) VALUES (2, 2, 1, 1)');
        $this->assertSame(200, $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['default_calendar' => 2]])->status, 'avec le droit de création partagé');
        $this->assertSame(null, $this->j($this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['default_calendar' => null]]))['ui']['default_calendar']);
    }

    public function testProfileUpdateAndAudit(): void
    {
        $r = $this->call('alice', 'PUT', '/api/v1/settings', ['profile' => ['first_name' => 'Alicia', 'last_name' => 'Martin', 'timezone' => 'America/Toronto']]);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(['Alicia', 'Martin', 'America/Toronto'], [$this->j($r)['profile']['first_name'], $this->j($r)['profile']['last_name'], $this->j($r)['profile']['timezone']]);
        $me = $this->j($this->call('alice', 'GET', '/api/v1/auth/me'))['user'];
        $this->assertSame(['Alicia', 'America/Toronto'], [$me['first_name'], $me['timezone']]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.profile_update' AND user_id = 2")->fetchColumn());
        foreach ([['timezone' => 'Mars/Phobos'], ['first_name' => ''], ['last_name' => str_repeat('x', 81)], ['email' => 'x@example.org'], ['username' => 'root'], ['role' => 'admin'], ['password_hash' => 'x']] as $bad) {
            $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/settings', ['profile' => $bad])->status, json_encode($bad));
        }
        $this->assertSame('alice', $this->pdo->query('SELECT username FROM users WHERE id = 2')->fetchColumn());
        $this->assertSame('user', $this->pdo->query('SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = 2')->fetchColumn(), 'aucune élévation de rôle possible');
    }

    public function testOrganisationSettingsAreAdminOnlyAndLayerUnderUserChoices(): void
    {
        foreach ([['GET', '/api/v1/settings/global'], ['PUT', '/api/v1/settings/global']] as [$m, $p]) {
            $this->assertSame(403, $this->call('alice', $m, $p, ['global' => ['org_name' => 'X']])->status, "$m $p");
        }
        $r = $this->call('admin', 'PUT', '/api/v1/settings/global', ['global' => ['org_name' => 'Cabinet Dupont', 'default_timezone' => 'America/Toronto'], 'ui' => ['view' => 'agenda', 'start_hour' => 7, 'end_hour' => 20, 'week_start' => 0]]);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(['Cabinet Dupont', 'America/Toronto'], array_values($this->j($r)['global']));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.global_update'")->fetchColumn());
        // les utilisateurs sans choix personnel héritent ; ceux qui ont choisi gardent leurs valeurs
        $bob = $this->j($this->call('bob', 'GET', '/api/v1/settings'))['ui'];
        $this->assertSame(['agenda', 7, 20, 0, 'auto'], [$bob['view'], $bob['start_hour'], $bob['end_hour'], $bob['week_start'], $bob['theme']]);
        $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['view' => 'week']]);
        $alice = $this->j($this->call('alice', 'GET', '/api/v1/settings'))['ui'];
        $this->assertSame(['week', 7], [$alice['view'], $alice['start_hour']], 'choix personnel prioritaire, le reste hérité');
        // validation
        foreach ([['global' => ['org_name' => str_repeat('x', 81)]], ['global' => ['default_timezone' => 'Nope/Zone']], ['global' => ['secret' => 1]], ['ui' => ['default_calendar' => 1]], ['ui' => ['hidden_calendars' => [1]]], ['ui' => ['view' => 'x']], [], ['other' => 1]] as $bad) {
            $this->assertSame(422, $this->call('admin', 'PUT', '/api/v1/settings/global', $bad)->status, json_encode($bad));
        }
        // le fuseau par défaut sert aux nouveaux comptes sans fuseau précisé
        $c = $this->call('admin', 'POST', '/api/v1/users', ['username' => 'carol', 'email' => 'carol@example.org', 'first_name' => 'Carol', 'last_name' => 'D', 'role' => 'user']);
        $this->assertSame('America/Toronto', $this->j($c)['data']['timezone']);
    }

    public function testPagesCarryServerSideSettings(): void
    {
        $this->call('admin', 'PUT', '/api/v1/settings/global', ['global' => ['org_name' => 'Cabinet <b>Dupont</b> & Fils']]);
        $this->call('alice', 'PUT', '/api/v1/settings', ['ui' => ['theme' => 'dark', 'view' => 'month', 'default_duration' => 30]]);
        $html = $this->page('alice', 'dashboard');
        $this->assertTrue(str_contains($html, 'data-theme="dark" data-theme-server="1"'), 'thème appliqué avant le rendu, sans flash');
        $this->assertTrue((bool) preg_match('/<meta name="ui-settings" content="([^"]+)"/', $html, $m));
        $meta = json_decode(html_entity_decode($m[1]), true);
        $this->assertSame(['dark', 'month', 30, true], [$meta['theme'], $meta['view'], $meta['default_duration'], $meta['stored']]);
        $this->assertTrue(str_contains($html, 'Cabinet &lt;b&gt;Dupont&lt;/b&gt; &amp; Fils') && !str_contains($html, '<b>Dupont'), 'nom de l\'organisation échappé');
        $this->assertTrue(str_contains($html, '<title>Tableau de bord – Cabinet'), 'titre de page');
        // vue d'ouverture du calendrier : préférence, sauf si l'adresse précise une vue
        $this->assertTrue(str_contains($this->page('alice', 'calendar'), 'data-view="month"'));
        $this->assertTrue(str_contains($this->page('alice', 'calendar', ['view' => 'day']), 'data-view="day"'));
        $this->assertTrue(str_contains($this->page('bob', 'calendar'), 'data-view="week"'), 'défaut de l\'organisation pour les autres');
        $this->assertTrue(!str_contains($this->page('bob', 'dashboard'), 'data-theme="'), 'thème automatique : aucun attribut imposé');
        // page de connexion
        $login = $this->pages->login(new Request('GET', '/login'))->body;
        $this->assertTrue(str_contains($login, 'Cabinet &lt;b&gt;Dupont&lt;/b&gt; &amp; Fils'));
    }

    public function testNotificationSettingsStillWorkThroughTheSameEndpoint(): void
    {
        $r = $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['privacy' => 'minimal', 'default_reminder' => 15]]);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(['minimal', 15], [$this->j($r)['notifications']['privacy'], $this->j($r)['notifications']['default_reminder']]);
        $both = $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['privacy' => 'full'], 'ui' => ['theme' => 'light']]);
        $this->assertSame(['full', 'light'], [$this->j($both)['notifications']['privacy'], $this->j($both)['ui']['theme']]);
    }
}
