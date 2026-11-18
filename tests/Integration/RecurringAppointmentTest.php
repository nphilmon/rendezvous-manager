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

final class RecurringAppointmentTest extends DatabaseTestCase
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
        foreach (['alice', 'bob'] as $n) {
            $this->users->create(['role_code' => 'user', 'username' => $n, 'email' => "$n@example.org", 'first_name' => $n, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'A'), (1,'A2'), (2,'B')");
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function call(string $as, string $method, string $path, array $body = [], array $query = []): array
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
        $h = ['cookie' => $this->sessions[$as]['cookie']];
        if ($method !== 'GET') {
            $h['x-csrf-token'] = $this->sessions[$as]['csrf'];
        }
        $res = Application::handle(new Request($method, $path, $query, $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
        return ['status' => $res->status, 'json' => json_decode($res->body, true) ?? []];
    }

    private function series(array $rec, array $over = []): array
    {
        $r = $this->call('alice', 'POST', '/api/v1/appointments', $over + ['calendar_id' => 1, 'title' => 'Série', 'timezone' => 'Europe/Paris',
            'start' => '2026-11-18T08:00:00Z', 'end' => '2026-11-18T09:00:00Z', 'recurrence' => $rec]);
        $this->assertSame(201, $r['status'], json_encode($r['json']));
        return $r['json']['data'];
    }

    /** @return list<array<string,mixed>> */
    private function range(string $from, string $to, string $as = 'alice'): array
    {
        $r = $this->call($as, 'GET', '/api/v1/appointments', [], ['start' => $from, 'end' => $to]);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        return $r['json']['data'];
    }

    private function starts(array $rows): array
    {
        return array_map(fn ($a) => substr($a['start'], 0, 16), $rows);
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
    }

    private const WEEKLY4 = ['freq' => 'weekly', 'end' => ['type' => 'count', 'count' => 4]];

    public function testSeriesIsStoredAsOneRowAndExpandedOnDemand(): void
    {
        $a = $this->series(self::WEEKLY4);
        $this->assertSame(1, $this->rowCount());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM appointment_recurrence')->fetchColumn());
        $this->assertSame('FREQ=WEEKLY;COUNT=4', $a['rrule']);
        $this->assertSame('weekly', $a['recurrence']['freq']);
        $occ = $this->range('2026-11-01', '2026-12-31');
        $this->assertSame(['2026-11-18T08:00', '2026-11-25T08:00', '2026-12-02T08:00', '2026-12-09T08:00'], $this->starts($occ));
        $this->assertSame([$a['id']], array_values(array_unique(array_column($occ, 'id'))));
        $this->assertTrue($occ[1]['recurring'] && $occ[1]['occurrence_start'] === '2026-11-25T08:00:00Z');
        $this->assertSame(['2026-11-25T08:00'], $this->starts($this->range('2026-11-25T08:30:00Z', '2026-11-25T08:45:00Z')), 'occurrence en cours dans la fenêtre');
        $this->assertSame([], $this->range('2026-12-10', '2027-01-30'), 'COUNT respecté');
    }

    public function testNeverEndingSeriesDoesNotCreateRows(): void
    {
        $this->series(['freq' => 'daily']);
        $far = $this->range('2035-06-01', '2035-06-04');
        $this->assertSame(3, count($far));
        $this->assertSame(1, $this->rowCount());
    }

    public function testDstKeepsLocalTime(): void
    {
        $this->series(['freq' => 'daily', 'end' => ['type' => 'count', 'count' => 3]], ['start' => '2027-03-27T08:00:00Z', 'end' => '2027-03-27T09:00:00Z']);
        $this->assertSame(['2027-03-27T08:00', '2027-03-28T07:00', '2027-03-29T07:00'], $this->starts($this->range('2027-03-20', '2027-04-05')), '09:00 Paris avant et après le passage à l\'heure d\'été');
    }

    public function testAllDayYearlySeries(): void
    {
        $this->series(['freq' => 'yearly'], ['all_day' => true, 'start' => '2026-11-18', 'end' => '2026-11-19', 'title' => 'Anniversaire']);
        $r = $this->range('2028-11-17', '2028-11-20');
        $this->assertSame(1, count($r));
        $this->assertSame(['2028-11-18T00:00:00Z', '2028-11-19T00:00:00Z'], [$r[0]['start'], $r[0]['end']]);
        $this->assertTrue($r[0]['all_day']);
    }

    public function testValidationAndScopeRequired(): void
    {
        foreach ([['freq' => 'hourly'], ['freq' => 'weekly', 'end' => ['type' => 'count', 'count' => 0]], ['freq' => 'weekly', 'byday' => ['2MO']], ['freq' => 'daily', 'interval' => 0],
            ['freq' => 'daily', 'end' => ['type' => 'until', 'until' => 'demain']], 'texte'] as $bad) {
            $r = $this->call('alice', 'POST', '/api/v1/appointments', ['calendar_id' => 1, 'title' => 'x', 'start' => '2026-11-18T08:00:00Z', 'end' => '2026-11-18T09:00:00Z', 'recurrence' => $bad]);
            $this->assertSame(422, $r['status'], json_encode($bad));
            $this->assertTrue(isset($r['json']['error']['details']['recurrence']));
        }
        $this->assertSame(0, $this->rowCount());
        $a = $this->series(self::WEEKLY4);
        $url = '/api/v1/appointments/' . $a['id'];
        $this->assertSame(422, $this->call('alice', 'PUT', $url, ['version' => 1, 'title' => 'sans portée'])['status']);
        $this->assertSame(422, $this->call('alice', 'DELETE', $url, ['version' => 1])['status']);
        $this->assertSame(422, $this->call('alice', 'PUT', $url, ['version' => 1, 'scope' => 'bof', 'title' => 'x'])['status']);
        $this->assertSame(422, $this->call('alice', 'PUT', $url, ['version' => 1, 'scope' => 'single', 'title' => 'x'])['status'], 'occurrence requise');
        $this->assertSame(404, $this->call('alice', 'PUT', $url, ['version' => 1, 'scope' => 'single', 'occurrence' => '2026-11-19T08:00:00Z', 'title' => 'x'])['status'], 'occurrence qui n\'existe pas');
        $this->assertSame(422, $this->call('alice', 'PUT', $url, ['version' => 1, 'scope' => 'single', 'occurrence' => 'hier', 'title' => 'x'])['status']);
    }

    public function testEditSingleOccurrence(): void
    {
        $a = $this->series(self::WEEKLY4);
        $url = '/api/v1/appointments/' . $a['id'];
        $r = $this->call('alice', 'PUT', $url, ['version' => 1, 'scope' => 'single', 'occurrence' => '2026-11-25T08:00:00Z', 'title' => 'Exception', 'start' => '2026-11-25T09:00:00Z', 'end' => '2026-11-25T10:30:00Z']);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        $this->assertSame('Exception', $r['json']['data']['title']);
        $this->assertSame(2, $r['json']['data']['version']);
        $occ = $this->range('2026-11-01', '2026-12-31');
        $this->assertSame(['2026-11-18T08:00', '2026-11-25T09:00', '2026-12-02T08:00', '2026-12-09T08:00'], $this->starts($occ));
        $this->assertSame(['Série', 'Exception', 'Série', 'Série'], array_column($occ, 'title'));
        $this->assertSame('2026-11-25T08:00:00Z', $occ[1]['occurrence_start'], 'clé d\'exception = début d\'origine');
        $this->assertSame('2026-11-25T10:30:00Z', $occ[1]['end']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM appointment_exceptions')->fetchColumn());
        // champs non modifiables pour une seule occurrence
        $this->assertSame(422, $this->call('alice', 'PUT', $url, ['version' => 2, 'scope' => 'single', 'occurrence' => '2026-12-02T08:00:00Z', 'calendar_id' => 2])['status']);
        // revenir à l'état de la série supprime l'exception
        $back = $this->call('alice', 'PUT', $url, ['version' => 2, 'scope' => 'single', 'occurrence' => '2026-11-25T08:00:00Z', 'title' => 'Série', 'start' => '2026-11-25T08:00:00Z', 'end' => '2026-11-25T09:00:00Z']);
        $this->assertSame(200, $back['status']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM appointment_exceptions')->fetchColumn());
    }

    public function testMovedOccurrenceIsFoundAtItsNewPosition(): void
    {
        $a = $this->series(['freq' => 'weekly']);
        $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'scope' => 'single', 'occurrence' => '2026-11-25T08:00:00Z', 'start' => '2027-02-10T08:00:00Z', 'end' => '2027-02-10T09:00:00Z']);
        $this->assertSame(['2027-02-10T08:00'], $this->starts(array_values(array_filter($this->range('2027-02-08', '2027-02-12'), fn ($x) => $x['occurrence_start'] === '2026-11-25T08:00:00Z'))), 'trouvée à sa nouvelle date');
        $this->assertSame([], array_values(array_filter($this->range('2026-11-24', '2026-11-27'), fn ($x) => $x['occurrence_start'] === '2026-11-25T08:00:00Z')), 'absente de l\'ancienne');
    }

    public function testCancelAndDeleteSingleOccurrence(): void
    {
        $a = $this->series(self::WEEKLY4);
        $url = '/api/v1/appointments/' . $a['id'];
        $c = $this->call('alice', 'POST', $url . '/cancel', ['version' => 1, 'scope' => 'single', 'occurrence' => '2026-11-25T08:00:00Z']);
        $this->assertSame('cancelled', $c['json']['data']['status']);
        $occ = $this->range('2026-11-01', '2026-12-31');
        $this->assertSame(['scheduled', 'cancelled', 'scheduled', 'scheduled'], array_column($occ, 'status'));
        $k = $this->call('alice', 'POST', $url . '/complete', ['version' => 2, 'scope' => 'single', 'occurrence' => '2026-11-18T08:00:00Z']);
        $this->assertSame('completed', $k['json']['data']['status']);
        $d = $this->call('alice', 'DELETE', $url, ['version' => 3, 'scope' => 'single', 'occurrence' => '2026-12-02T08:00:00Z']);
        $this->assertSame(200, $d['status']);
        $this->assertSame(['2026-11-18T08:00', '2026-11-25T08:00', '2026-12-09T08:00'], $this->starts($this->range('2026-11-01', '2026-12-31')));
        $this->assertSame(1, $this->rowCount(), 'aucune ligne créée par ces opérations');
    }

    public function testEditAllKeepsOccurrenceOverridesAndShiftsTimeOfDay(): void
    {
        $a = $this->series(self::WEEKLY4);
        $url = '/api/v1/appointments/' . $a['id'];
        $this->call('alice', 'PUT', $url, ['version' => 1, 'scope' => 'single', 'occurrence' => '2026-11-25T08:00:00Z', 'title' => 'Spécial']);
        $r = $this->call('alice', 'PUT', $url, ['version' => 2, 'scope' => 'all', 'occurrence' => '2026-12-02T08:00:00Z', 'title' => 'Renommée', 'location' => 'Salle 3']);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        $occ = $this->range('2026-11-01', '2026-12-31');
        $this->assertSame(['Renommée', 'Spécial', 'Renommée', 'Renommée'], array_column($occ, 'title'), 'l\'exception garde son titre propre');
        $this->assertSame(['Salle 3', 'Salle 3', 'Salle 3', 'Salle 3'], array_column($occ, 'location'));
        // décaler d'une heure toute la série (depuis une occurrence quelconque) : la date de la série ne bouge pas
        $s = $this->call('alice', 'POST', $url . '/reschedule', ['version' => 3, 'scope' => 'all', 'occurrence' => '2026-12-02T08:00:00Z', 'start' => '2026-12-02T09:00:00Z', 'end' => '2026-12-02T10:00:00Z']);
        $this->assertSame(200, $s['status'], json_encode($s['json']));
        $this->assertSame(['2026-11-18T09:00', '2026-11-25T09:00', '2026-12-02T09:00', '2026-12-09T09:00'], $this->starts($this->range('2026-11-01', '2026-12-31')));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM appointment_exceptions')->fetchColumn(), 'exceptions effacées quand l\'horaire de la série change');
    }

    public function testEditFollowingSplitsTheSeries(): void
    {
        $a = $this->series(self::WEEKLY4);
        $url = '/api/v1/appointments/' . $a['id'];
        $r = $this->call('alice', 'PUT', $url, ['version' => 1, 'scope' => 'following', 'occurrence' => '2026-12-02T08:00:00Z', 'title' => 'Nouvelle série', 'start' => '2026-12-02T10:00:00Z', 'end' => '2026-12-02T11:00:00Z']);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        $new = $r['json']['data'];
        $this->assertTrue($new['id'] !== $a['id'] && $new['title'] === 'Nouvelle série');
        $this->assertSame(2, $this->rowCount());
        $occ = $this->range('2026-11-01', '2026-12-31');
        $this->assertSame(['2026-11-18T08:00', '2026-11-25T08:00', '2026-12-02T10:00', '2026-12-09T10:00'], $this->starts($occ));
        $this->assertSame(['Série', 'Série', 'Nouvelle série', 'Nouvelle série'], array_column($occ, 'title'));
        $old = $this->call('alice', 'GET', $url)['json']['data'];
        $this->assertSame('weekly', $old['recurrence']['freq']);
        $this->assertSame('until', $old['recurrence']['end']['type'], 'ancienne série bornée avant la coupure (COUNT devient UNTIL)');
        $this->assertSame(4, (int) $this->pdo->query("SELECT COUNT(*) FROM appointment_recurrence WHERE rrule LIKE '%COUNT=2%' OR rrule LIKE '%UNTIL%'")->fetchColumn() + 2, 'COUNT restant reporté sur la nouvelle série');
        $this->assertSame('FREQ=WEEKLY;COUNT=2', $this->pdo->query("SELECT rrule FROM appointment_recurrence WHERE appointment_id = {$new['id']}")->fetchColumn());
    }

    public function testFollowingFromFirstOccurrenceIsWholeSeries(): void
    {
        $a = $this->series(self::WEEKLY4);
        $r = $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'scope' => 'following', 'occurrence' => '2026-11-18T08:00:00Z', 'title' => 'Tout']);
        $this->assertSame($a['id'], $r['json']['data']['id']);
        $this->assertSame(1, $this->rowCount());
        $this->assertSame(['Tout', 'Tout', 'Tout', 'Tout'], array_column($this->range('2026-11-01', '2026-12-31'), 'title'));
    }

    public function testFollowingWithNewRecurrenceRule(): void
    {
        $a = $this->series(['freq' => 'weekly']);
        $r = $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'scope' => 'following', 'occurrence' => '2026-12-02T08:00:00Z',
            'recurrence' => ['freq' => 'daily', 'end' => ['type' => 'count', 'count' => 2]]]);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        $this->assertSame(['2026-11-18T08:00', '2026-11-25T08:00', '2026-12-02T08:00', '2026-12-03T08:00'], $this->starts($this->range('2026-11-01', '2026-12-31')));
    }

    public function testDeleteFollowingAndAll(): void
    {
        $a = $this->series(['freq' => 'weekly']);
        $url = '/api/v1/appointments/' . $a['id'];
        $this->assertSame(200, $this->call('alice', 'DELETE', $url, ['version' => 1, 'scope' => 'following', 'occurrence' => '2026-12-02T08:00:00Z'])['status']);
        $this->assertSame(['2026-11-18T08:00', '2026-11-25T08:00'], $this->starts($this->range('2026-11-01', '2027-02-01')));
        $this->assertSame(409, $this->call('alice', 'DELETE', $url, ['version' => 1, 'scope' => 'all'])['status'], 'version périmée');
        $this->assertSame(200, $this->call('alice', 'DELETE', $url, ['version' => 2, 'scope' => 'all'])['status']);
        $this->assertSame([], $this->range('2026-11-01', '2027-02-01'));
        $this->assertSame(200, $this->call('alice', 'POST', $url . '/restore')['status']);
        $this->assertSame(2, count($this->range('2026-11-01', '2027-02-01')), 'la série restaurée garde sa borne');
    }

    public function testDeleteFollowingFromFirstDeletesSeries(): void
    {
        $a = $this->series(['freq' => 'weekly']);
        $this->call('alice', 'DELETE', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'scope' => 'following', 'occurrence' => '2026-11-18T08:00:00Z']);
        $this->assertSame([], $this->range('2026-11-01', '2027-02-01'));
    }

    public function testGetOccurrenceAndDuplicate(): void
    {
        $a = $this->series(self::WEEKLY4);
        $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'scope' => 'single', 'occurrence' => '2026-11-25T08:00:00Z', 'title' => 'Spécial']);
        $g = $this->call('alice', 'GET', '/api/v1/appointments/' . $a['id'], [], ['occurrence' => '2026-11-25T08:00:00Z']);
        $this->assertSame('Spécial', $g['json']['data']['title']);
        $this->assertSame('2026-11-25T08:00:00Z', $g['json']['data']['start']);
        $this->assertSame(404, $this->call('alice', 'GET', '/api/v1/appointments/' . $a['id'], [], ['occurrence' => '2026-11-26T08:00:00Z'])['status']);
        $d = $this->call('alice', 'POST', '/api/v1/appointments/' . $a['id'] . '/duplicate', ['occurrence' => '2026-12-02T08:00:00Z']);
        $this->assertSame(201, $d['status']);
        $this->assertSame('2026-12-02T08:00:00Z', $d['json']['data']['start']);
        $this->assertSame(false, $d['json']['data']['recurring'], 'la copie d\'une occurrence est un rendez-vous simple');
    }

    public function testConvertToSeriesAndBack(): void
    {
        $r = $this->call('alice', 'POST', '/api/v1/appointments', ['calendar_id' => 1, 'title' => 'Simple', 'start' => '2026-11-18T08:00:00Z', 'end' => '2026-11-18T09:00:00Z']);
        $id = $r['json']['data']['id'];
        $u = $this->call('alice', 'PUT', '/api/v1/appointments/' . $id, ['version' => 1, 'recurrence' => ['freq' => 'daily', 'end' => ['type' => 'count', 'count' => 3]]]);
        $this->assertSame(200, $u['status'], json_encode($u['json']));
        $this->assertSame(3, count($this->range('2026-11-01', '2026-12-01')));
        $b = $this->call('alice', 'PUT', '/api/v1/appointments/' . $id, ['version' => 2, 'scope' => 'all', 'recurrence' => null]);
        $this->assertSame(200, $b['status'], json_encode($b['json']));
        $this->assertSame(1, count($this->range('2026-11-01', '2026-12-01')));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM appointment_recurrence')->fetchColumn());
    }

    public function testSeriesDoNotConflictButSingleEventsStillDo(): void
    {
        $this->series(['freq' => 'daily']);
        $ok = $this->call('alice', 'POST', '/api/v1/appointments', ['calendar_id' => 1, 'title' => 'Pile dessus', 'start' => '2026-11-18T08:00:00Z', 'end' => '2026-11-18T09:00:00Z']);
        $this->assertSame(201, $ok['status'], 'une série ne bloque pas les autres rendez-vous');
        $dup = $this->call('alice', 'POST', '/api/v1/appointments', ['calendar_id' => 1, 'title' => 'Conflit', 'start' => '2026-11-18T08:30:00Z', 'end' => '2026-11-18T09:30:00Z']);
        $this->assertSame(409, $dup['status']);
    }

    public function testPermissionsOnSeries(): void
    {
        $a = $this->series(self::WEEKLY4);
        $url = '/api/v1/appointments/' . $a['id'];
        $this->assertSame([], $this->range('2026-11-01', '2026-12-31', 'bob'), 'Bob ne voit pas la série');
        $this->pdo->exec('INSERT INTO calendar_permissions (calendar_id, user_id, can_read) VALUES (1, 2, 1)');
        $this->assertSame(4, count($this->range('2026-11-01', '2026-12-31', 'bob')));
        $e = $this->call('bob', 'PUT', $url, ['version' => 1, 'scope' => 'single', 'occurrence' => '2026-11-25T08:00:00Z', 'title' => 'Piraté']);
        $this->assertSame(403, $e['status']);
        $this->assertSame(403, $this->call('bob', 'DELETE', $url, ['version' => 1, 'scope' => 'all'])['status']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM appointment_exceptions')->fetchColumn());
    }

    public function testListFiltersExpandSeriesAndOrderByOccurrence(): void
    {
        $this->series(['freq' => 'daily'], ['title' => 'Quotidien', 'start' => '2026-11-01T08:00:00Z', 'end' => '2026-11-01T09:00:00Z']);
        $this->series(['freq' => 'daily', 'end' => ['type' => 'count', 'count' => 3]], ['title' => 'Fini', 'start' => '2026-10-01T08:00:00Z', 'end' => '2026-10-01T09:00:00Z']);
        $this->call('alice', 'POST', '/api/v1/appointments', ['calendar_id' => 1, 'title' => 'Simple', 'start' => '2026-11-18T14:00:00Z', 'end' => '2026-11-18T15:00:00Z']);
        $plain = $this->call('alice', 'GET', '/api/v1/appointments', [], ['sort' => 'start', 'dir' => 'asc']);
        $this->assertSame(['Fini', 'Quotidien', 'Simple'], array_column($plain['json']['data'], 'title'), 'sans période : une ligne par série');
        $day = $this->call('alice', 'GET', '/api/v1/appointments', [], ['from' => '2026-11-18T00:00:00Z', 'to' => '2026-11-19T00:00:00Z', 'sort' => 'start']);
        $this->assertSame(['Quotidien', 'Simple'], array_column($day['json']['data'], 'title'), 'la série terminée est exclue');
        $this->assertSame('2026-11-18T08:00:00Z', $day['json']['data'][0]['start'], 'occurrence du jour, pas le premier rendez-vous');
        $this->assertSame('2026-11-18T08:00:00Z', $day['json']['data'][0]['occurrence_start']);
        $this->assertSame(2, $day['json']['meta']['total']);
        $up = $this->call('alice', 'GET', '/api/v1/appointments', [], ['from' => '2026-11-18T09:30:00Z', 'sort' => 'start']);
        $this->assertSame(['2026-11-18T14:00:00Z', '2026-11-19T08:00:00Z'], array_column($up['json']['data'], 'start'), 'à venir : prochaine occurrence de chaque série, triée');
        $run = $this->call('alice', 'GET', '/api/v1/appointments', [], ['from' => '2026-11-18T08:30:00Z', 'sort' => 'start']);
        $this->assertSame('2026-11-18T08:00:00Z', $run['json']['data'][0]['start'], 'une occurrence en cours compte comme à venir');
    }
}
