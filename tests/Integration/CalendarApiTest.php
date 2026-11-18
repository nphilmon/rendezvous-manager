<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\CalendarApiController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\Authenticator;
use App\Repositories\AppointmentRepository;
use App\Repositories\CalendarRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\PermissionService;
use Tests\DatabaseTestCase;

final class CalendarApiTest extends DatabaseTestCase
{
    private AuthService $auth;
    private Router $router;
    private UserRepository $users;

    public function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->auth = new AuthService($this->pdo, $this->users, new AuditLogger($this->pdo));
        $c = new CalendarApiController(new Authenticator($this->auth), new PermissionService($this->pdo), new CalendarRepository($this->pdo), new AppointmentRepository($this->pdo));
        $this->router = new Router();
        $this->router->add('GET', '/api/v1/calendars', fn ($q) => $c->calendars($q));
        $this->router->add('GET', '/api/v1/appointments', fn ($q) => $c->appointments($q));
    }

    private function user(string $n, string $role = 'user'): int
    {
        return $this->users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => $n, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
    }

    /** @return array<string,mixed> */
    private function get(string $path, array $q = [], ?string $as = null): array
    {
        $h = $as ? ['cookie' => 'rvm_session=' . $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't')['token']] : [];
        $r = Application::handle(new Request('GET', $path, $q, '', $h), $this->router);
        return ['status' => $r->status, 'json' => json_decode($r->body, true)];
    }

    private function appt(int $cal, string $title, string $s, string $e, array $extra = []): int
    {
        $this->pdo->prepare('INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc, all_day, status, deleted_at) VALUES (UUID(),?,1,?,?,?,?,?,?)')
            ->execute([$cal, $title, $s, $e, $extra['all_day'] ?? 0, $extra['status'] ?? 'scheduled', $extra['deleted'] ?? null]);
        return (int) $this->pdo->lastInsertId();
    }

    private function titles(array $res): array
    {
        return array_column($res['json']['data'], 'title');
    }

    public function testAuthenticationAndReadPermissionRequired(): void
    {
        $this->assertSame(401, $this->get('/api/v1/calendars')['status']);
        $this->assertSame(401, $this->get('/api/v1/appointments', ['start' => '2026-11-01', 'end' => '2026-12-01'])['status']);
    }

    public function testCalendarListIsScopedAndReportsPermissions(): void
    {
        $a = $this->user('alice');
        $b = $this->user('bob');
        $this->pdo->exec("INSERT INTO calendars (owner_id, name, visibility) VALUES ($a,'A privé','private'), ($b,'B privé','private'), ($b,'B orga','organization'), ($b,'B partagé','private')");
        $this->pdo->exec("INSERT INTO calendar_permissions (calendar_id, user_id, can_read, can_create) VALUES (4,$a,1,1)");
        $r = $this->get('/api/v1/calendars', [], 'alice');
        $names = array_column($r['json']['data'], 'name');
        sort($names);
        $this->assertSame(['A privé', 'B orga', 'B partagé'], $names);
        $byName = array_column($r['json']['data'], null, 'name');
        $this->assertTrue($byName['A privé']['mine'] && $byName['A privé']['can']['delete']);
        $this->assertTrue($byName['B partagé']['can']['create'] && !$byName['B partagé']['can']['update']);
        $this->assertTrue(!$byName['B orga']['can']['create']);
    }

    public function testRangeOverlapBoundariesAndDeletedExcluded(): void
    {
        $a = $this->user('alice');
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES ($a,'A')");
        $this->appt(1, 'avant', '2026-11-17 10:00:00', '2026-11-18 00:00:00');   // finit pile au début : exclu
        $this->appt(1, 'chevauche début', '2026-11-17 23:00:00', '2026-11-18 01:00:00');
        $this->appt(1, 'dedans', '2026-11-18 10:00:00', '2026-11-18 11:00:00');
        $this->appt(1, 'chevauche fin', '2026-11-18 23:00:00', '2026-11-19 01:00:00');
        $this->appt(1, 'après', '2026-11-19 00:00:00', '2026-11-19 01:00:00');   // commence pile à la fin : exclu
        $this->appt(1, 'supprimé', '2026-11-18 12:00:00', '2026-11-18 13:00:00', ['deleted' => '2026-11-01 00:00:00']);
        $this->appt(1, 'annulé', '2026-11-18 14:00:00', '2026-11-18 15:00:00', ['status' => 'cancelled']);
        $r = $this->get('/api/v1/appointments', ['start' => '2026-11-18T00:00:00Z', 'end' => '2026-11-19T00:00:00Z'], 'alice');
        $this->assertSame(200, $r['status']);
        $this->assertSame(['chevauche début', 'dedans', 'annulé', 'chevauche fin'], $this->titles($r));
        $this->assertSame(false, $r['json']['meta']['truncated']);
        $one = $r['json']['data'][1];
        $this->assertSame('2026-11-18T10:00:00Z', $one['start']);
        $this->assertSame(false, $one['all_day']);
    }

    public function testAppointmentsOfOtherUsersCalendarsAreNeverReturned(): void
    {
        $a = $this->user('alice');
        $b = $this->user('bob');
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES ($a,'A'), ($b,'B')");
        $this->appt(1, 'à alice', '2026-11-18 10:00:00', '2026-11-18 11:00:00');
        $this->appt(2, 'secret bob', '2026-11-18 10:00:00', '2026-11-18 11:00:00');
        $q = ['start' => '2026-11-18', 'end' => '2026-11-19'];
        $this->assertSame(['à alice'], $this->titles($this->get('/api/v1/appointments', $q, 'alice')));
        $this->assertSame(['à alice'], $this->titles($this->get('/api/v1/appointments', $q + ['calendars' => '1,2'], 'alice')), 'filtre ne contourne pas les droits');
        $this->assertSame([], $this->titles($this->get('/api/v1/appointments', $q + ['calendars' => '2'], 'alice')));
        $this->assertSame(['secret bob'], $this->titles($this->get('/api/v1/appointments', $q, 'bob')));
        $this->pdo->exec("INSERT INTO calendar_permissions (calendar_id, user_id, can_read) VALUES (2,$a,1)");
        $this->assertSame(['à alice', 'secret bob'], $this->titles($this->get('/api/v1/appointments', $q, 'alice')), 'partage');
    }

    public function testValidation(): void
    {
        $this->user('alice');
        $ok = ['start' => '2026-11-18', 'end' => '2026-11-19'];
        $cases = [
            [[], 'start/end absents'],
            [['start' => 'demain', 'end' => '2026-11-19'], 'start invalide'],
            [['start' => '2026-11-19', 'end' => '2026-11-18'], 'fin avant début'],
            [['start' => '2026-11-18', 'end' => '2026-11-18'], 'plage vide'],
            [['start' => '2026-01-01', 'end' => '2026-06-01'], 'plage > 100 jours'],
            [$ok + ['calendars' => '1;DROP'], 'calendars invalide'],
            [['start' => '2026-13-45', 'end' => '2026-14-01'], 'date impossible'],
        ];
        foreach ($cases as [$q, $why]) {
            $this->assertSame(422, $this->get('/api/v1/appointments', $q, 'alice')['status'], $why);
        }
        $this->assertSame(200, $this->get('/api/v1/appointments', ['start' => '2026-11-15T23:00:00.000Z', 'end' => '2026-11-22T23:00:00.000Z'], 'alice')['status'], 'millisecondes (JS toISOString)');
        $this->assertSame(200, $this->get('/api/v1/appointments', ['start' => '2026-11-15T23:00:00+01:00', 'end' => '2026-11-22T23:00:00+01:00'], 'alice')['status'], 'décalage horaire');
    }

    public function testFieldsAllDayCategoryContactAndRecurringFlag(): void
    {
        $a = $this->user('alice');
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES ($a,'A')");
        $this->pdo->exec("INSERT INTO categories (name, color) VALUES ('Client', '#9333ea')");
        $this->pdo->exec("INSERT INTO contacts (owner_id, last_name, first_name) VALUES ($a, 'Dupont', 'Marie')");
        $id = $this->appt(1, 'Journée', '2026-11-18 00:00:00', '2026-11-19 00:00:00', ['all_day' => 1]);
        $this->pdo->exec("UPDATE appointments SET category_id = 1, contact_id = 1, location = 'Lyon' WHERE id = $id");
        $this->pdo->exec("INSERT INTO appointment_recurrence (appointment_id, rrule, dtstart_local, timezone) VALUES ($id, 'FREQ=WEEKLY', '2026-11-18 00:00:00', 'Europe/Paris')");
        $d = $this->get('/api/v1/appointments', ['start' => '2026-11-18', 'end' => '2026-11-19'], 'alice')['json']['data'][0];
        $this->assertTrue($d['all_day'] && $d['recurring']);
        $this->assertSame('Marie Dupont', $d['contact']);
        $this->assertSame(['id' => 1, 'name' => 'Client', 'color' => '#9333ea'], $d['category']);
        $this->assertSame('2026-11-18T00:00:00Z', $d['start']);
        $this->assertTrue(!array_key_exists('created_by', $d) && !array_key_exists('password_hash', $d));
    }
}
