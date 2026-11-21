<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use Tests\DatabaseTestCase;

final class SearchRemindersTest extends DatabaseTestCase
{
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
        foreach (['alice' => 'user', 'bob' => 'user', 'ro' => 'readonly'] as $n => $role) {
            $users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => ucfirst($n), 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("INSERT INTO calendars (owner_id, name, visibility) VALUES (1,'Alice','private'), (2,'Bob','private')");
        $this->pdo->exec("INSERT INTO categories (name, color) VALUES ('Médical', '#2563eb'), ('Autre', '#16a34a')");
        $this->pdo->exec("INSERT INTO contacts (owner_id, last_name, first_name, created_at, updated_at) VALUES (1,'Dupont','Marie',NOW(),NOW()), (2,'Dupontel','Secret',NOW(),NOW()), (1,'Martin','Paul',NOW(),NOW())");
    }

    private function call(string $as, string $method, string $path, array $body = [], array $query = []): Response
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
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

    private function appt(string $as, int $cal, string $title, array $extra = []): int
    {
        $r = $this->call($as, 'POST', '/api/v1/appointments', $extra + ['ignore_conflicts' => true, 'calendar_id' => $cal, 'title' => $title, 'timezone' => 'Europe/Paris', 'start' => '2026-12-01T09:00:00Z', 'end' => '2026-12-01T10:00:00Z']);
        $this->assertSame(201, $r->status, $r->body);
        return $this->j($r)['data']['id'];
    }

    public function testGlobalSearchIsScopedAndValidated(): void
    {
        $this->appt('alice', 1, 'Consultation Dupont', ['description' => 'rien']);
        $this->appt('alice', 1, 'Autre chose', ['description' => 'dossier dupont urgent']);
        $this->appt('bob', 2, 'Rendez-vous Dupont de Bob');
        $d = $this->j($this->call('alice', 'GET', '/api/v1/search', [], ['q' => 'dupont']))['data'];
        $this->assertSame(2, $d['appointments']['total'], 'titre ET description ; jamais le calendrier de Bob');
        $this->assertSame(1, $d['contacts']['total'], 'seulement ses propres contacts (pas « Dupontel » de Bob)');
        $this->assertSame('Marie Dupont', $d['contacts']['items'][0]['name']);
        $this->assertTrue(!str_contains(json_encode($d), 'Secret') && !str_contains(json_encode($d), 'de Bob'));
        $this->assertSame(0, $this->j($this->call('alice', 'GET', '/api/v1/search', [], ['q' => '%%']))['data']['appointments']['total'], 'jokers échappés');
        foreach ([[], ['q' => 'a'], ['q' => str_repeat('x', 101)], ['q' => ['x']]] as $bad) {
            $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/search', [], $bad)->status, json_encode($bad));
        }
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/search', ['q' => 'dupont'], '', [], '203.0.113.1'), $this->router)->status);
    }

    public function testCombinedFiltersAndFacets(): void
    {
        $a = $this->appt('alice', 1, 'Alpha', ['category_id' => 1, 'contact_id' => 1, 'priority' => 'high', 'status' => 'confirmed']);
        $this->appt('alice', 1, 'Beta', ['category_id' => 2, 'priority' => 'low']);
        $this->appt('alice', 1, 'Gamma', ['category_id' => 1, 'contact_id' => 3, 'priority' => 'high']);
        $list = fn (array $q) => array_column($this->j($this->call('alice', 'GET', '/api/v1/appointments', [], $q + ['sort' => 'title', 'dir' => 'asc']))['data'], 'title');
        $this->assertSame(['Alpha', 'Gamma'], $list(['category_id' => '1']));
        $this->assertSame(['Alpha'], $list(['category_id' => '1', 'contact_id' => '1']));
        $this->assertSame(['Alpha'], $list(['category_id' => '1', 'priority' => 'high', 'status' => 'confirmed', 'q' => 'alp']));
        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $list(['created_by' => '1']));
        $this->assertSame([], $list(['created_by' => '2']));
        $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/appointments', [], ['created_by' => 'x'])->status);
        $this->appt('bob', 2, 'Chez Bob');
        $f = $this->j($this->call('alice', 'GET', '/api/v1/search/facets'))['data']['creators'];
        $this->assertSame([['id' => 1, 'name' => 'Alice T']], $f, 'seuls les créateurs de rendez-vous visibles');
        $this->assertSame($a, $this->j($this->call('alice', 'GET', '/api/v1/appointments', [], ['contact_id' => '1']))['data'][0]['id']);
    }

    public function testRemindersApi(): void
    {
        $a = $this->appt('alice', 1, 'Avec rappel');
        $other = $this->appt('bob', 2, 'Chez Bob');
        $this->assertSame([], $this->j($this->call('alice', 'GET', '/api/v1/reminders'))['data']);
        $r = $this->call('alice', 'POST', '/api/v1/reminders', ['appointment_id' => $a, 'minutes' => 30]);
        $this->assertSame(201, $r->status, $r->body);
        $this->assertSame(['appointment_id' => $a, 'minutes' => 30, 'channels' => ['inapp']], $this->j($r)['data']);
        $this->call('alice', 'POST', '/api/v1/reminders', ['appointment_id' => $a, 'minutes' => 10]); // remplace
        $list = $this->j($this->call('alice', 'GET', '/api/v1/reminders'));
        $this->assertSame(1, $list['meta']['total']);
        $this->assertSame([10, 'Avec rappel', ['inapp']], [$list['data'][0]['minutes'], $list['data'][0]['appointment']['title'], $list['data'][0]['channels']]);
        $this->assertSame(10, $this->j($this->call('alice', 'GET', '/api/v1/appointments/' . $a))['data']['reminder_minutes'], 'cohérent avec le rendez-vous');
        // isolement : un rendez-vous d'un calendrier non lisible est introuvable, les rappels d'autrui sont invisibles
        $this->assertSame(404, $this->call('alice', 'POST', '/api/v1/reminders', ['appointment_id' => $other, 'minutes' => 5])->status);
        $this->call('bob', 'POST', '/api/v1/reminders', ['appointment_id' => $other, 'minutes' => 60]);
        $this->assertSame(1, $this->j($this->call('alice', 'GET', '/api/v1/reminders'))['meta']['total']);
        $this->assertSame(404, $this->call('alice', 'DELETE', '/api/v1/reminders/' . $other)->status, 'pas le rappel d\'un autre');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM reminders WHERE appointment_id = $other")->fetchColumn());
        foreach ([[], ['appointment_id' => $a], ['appointment_id' => $a, 'minutes' => -1], ['appointment_id' => $a, 'minutes' => 'dix'], ['appointment_id' => $a, 'minutes' => 99999], ['appointment_id' => 0, 'minutes' => 5], ['appointment_id' => 99999, 'minutes' => 5]] as $bad) {
            $st = $this->call('alice', 'POST', '/api/v1/reminders', $bad)->status;
            $this->assertTrue(in_array($st, [404, 422], true), json_encode($bad) . " → $st");
        }
        foreach ([['per_page' => '0'], ['per_page' => '101'], ['appointment_id' => 'x']] as $bad) {
            $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/reminders', [], $bad)->status, json_encode($bad));
        }
        $this->assertSame(200, $this->call('alice', 'DELETE', '/api/v1/reminders/' . $a)->status);
        $this->assertSame([], $this->j($this->call('alice', 'GET', '/api/v1/reminders'))['data']);
        $this->assertSame(401, Application::handle(new Request('POST', '/api/v1/reminders', [], '{}', [], '203.0.113.1'), $this->router)->status);
    }
}
