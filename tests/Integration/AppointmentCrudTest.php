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

/** CRUD des rendez-vous via la vraie table de routes (cookie + CSRF comme un navigateur). */
final class AppointmentCrudTest extends DatabaseTestCase
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
        $this->user('alice');
        $this->user('bob');
        $this->user('ro', 'readonly');
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'A'), (2,'B')");
    }

    private function user(string $n, string $role = 'user'): int
    {
        return $this->users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => $n, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function call(string $as, string $method, string $path, array $body = [], array $query = [], bool $csrf = true): array
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
        $h = ['cookie' => $this->sessions[$as]['cookie']];
        if ($csrf && $method !== 'GET') {
            $h['x-csrf-token'] = $this->sessions[$as]['csrf'];
        }
        $res = Application::handle(new Request($method, $path, $query, $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
        return ['status' => $res->status, 'json' => json_decode($res->body, true) ?? []];
    }

    private function payload(array $over = []): array
    {
        return $over + ['calendar_id' => 1, 'title' => 'Réunion', 'start' => '2026-11-18T09:00:00Z', 'end' => '2026-11-18T10:00:00Z'];
    }

    private function create(string $as, array $over = []): array
    {
        $r = $this->call($as, 'POST', '/api/v1/appointments', $this->payload($over));
        $this->assertSame(201, $r['status'], 'création : ' . json_encode($r['json']));
        return $r['json']['data'];
    }

    public function testCreateReadAndDefaults(): void
    {
        $a = $this->create('alice', ['description' => 'D', 'location' => 'Salle', 'priority' => 'high', 'color' => '#AABBCC', 'reminder_minutes' => 15]);
        $this->assertSame(1, $a['version']);
        $this->assertSame('scheduled', $a['status']);
        $this->assertSame('#aabbcc', $a['color']);
        $this->assertSame(15, $a['reminder_minutes']);
        $this->assertSame('2026-11-18T09:00:00Z', $a['start']);
        $g = $this->call('alice', 'GET', '/api/v1/appointments/' . $a['id'])['json']['data'];
        $this->assertSame('Réunion', $g['title']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM reminders WHERE offset_minutes = 15 AND channel = 'inapp'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sync_changes WHERE change_type = 'created'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'appointment.create'")->fetchColumn());
    }

    public function testTimezoneOffsetIsConvertedToUtc(): void
    {
        $a = $this->create('alice', ['start' => '2026-11-18T10:00:00+01:00', 'end' => '2026-11-18T11:00:00+01:00']);
        $this->assertSame('2026-11-18T09:00:00Z', $a['start']);
    }

    public function testAllDayIsStoredAsFloatingDates(): void
    {
        $a = $this->create('alice', ['all_day' => true, 'start' => '2026-11-18', 'end' => '2026-11-20']);
        $this->assertSame('2026-11-18T00:00:00Z', $a['start']);
        $this->assertSame('2026-11-20T00:00:00Z', $a['end']);
        $r = $this->call('alice', 'POST', '/api/v1/appointments', $this->payload(['all_day' => true, 'start' => '2026-11-18', 'end' => '2026-11-18']));
        $this->assertSame(422, $r['status']);
        // un second rendez-vous « journée entière » le même jour ne crée pas de conflit
        $this->create('alice', ['all_day' => true, 'start' => '2026-11-18', 'end' => '2026-11-19', 'title' => 'autre']);
    }

    public function testValidationRejectsBadInput(): void
    {
        $cases = [
            ['title' => '', 'titre vide'], ['title' => str_repeat('x', 201), 'titre trop long'],
            ['end' => '2026-11-18T08:00:00Z', 'fin avant début'], ['start' => '2026-11-18T09:00:00', 'sans fuseau'],
            ['status' => 'nope', 'statut'], ['priority' => 'nope', 'priorité'], ['color' => 'red', 'couleur'],
            ['timezone' => 'Mars/Olympus', 'fuseau'], ['reminder_minutes' => 999999, 'rappel'], ['calendar_id' => 'abc', 'calendrier'],
            ['category_id' => 99, 'catégorie inexistante'], ['contact_id' => 99, 'contact inexistant'], ['all_day' => 'peut-être', 'all_day'],
            ['end' => '2028-11-18T09:00:00Z', 'durée > 366 jours'],
        ];
        foreach ($cases as $c) {
            $why = array_pop($c);
            $r = $this->call('alice', 'POST', '/api/v1/appointments', $this->payload($c));
            $this->assertSame(422, $r['status'], $why . ' : ' . json_encode($r['json']));
            $this->assertTrue(isset($r['json']['error']['details']), "détails manquants : $why");
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn());
    }

    public function testCsrfAndAuthRequiredForWrites(): void
    {
        $this->assertSame(403, $this->call('alice', 'POST', '/api/v1/appointments', $this->payload(), [], false)['status']);
        $res = Application::handle(new Request('POST', '/api/v1/appointments', [], json_encode($this->payload()), []), $this->router);
        $this->assertSame(401, $res->status);
    }

    public function testPermissionsPerCalendarAndRole(): void
    {
        $this->assertSame(403, $this->call('bob', 'POST', '/api/v1/appointments', $this->payload(['calendar_id' => 1]))['status'], 'pas de droit de création');
        $this->assertSame(403, $this->call('ro', 'POST', '/api/v1/appointments', $this->payload())['status'], 'lecture seule');
        $a = $this->create('alice');
        $this->assertSame(404, $this->call('bob', 'GET', '/api/v1/appointments/' . $a['id'])['status'], 'non lisible : 404 et non 403');
        $this->pdo->exec('INSERT INTO calendar_permissions (calendar_id, user_id, can_read) VALUES (1, 2, 1)');
        $this->assertSame(200, $this->call('bob', 'GET', '/api/v1/appointments/' . $a['id'])['status']);
        $this->assertSame(403, $this->call('bob', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'title' => 'x'])['status']);
        $this->assertSame(403, $this->call('bob', 'DELETE', '/api/v1/appointments/' . $a['id'], ['version' => 1])['status']);
        $this->pdo->exec('UPDATE calendar_permissions SET can_update = 1, can_delete = 1, can_create = 1');
        $this->assertSame(200, $this->call('bob', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'title' => 'par Bob'])['status']);
    }

    public function testUpdateMergesAndBumpsVersion(): void
    {
        $a = $this->create('alice', ['location' => 'Lyon']);
        $r = $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'title' => 'Nouveau titre']);
        $this->assertSame(200, $r['status']);
        $d = $r['json']['data'];
        $this->assertSame(2, $d['version']);
        $this->assertSame('Nouveau titre', $d['title']);
        $this->assertSame('Lyon', $d['location'], 'champ omis conservé');
        $this->assertSame($a['start'], $d['start']);
        $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['title' => 'sans version'])['status']);
    }

    public function testOptimisticConcurrency(): void
    {
        $a = $this->create('alice');
        $this->assertSame(200, $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'title' => 'v2'])['status']);
        $r = $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'title' => 'écrasement'] );
        $this->assertSame(409, $r['status']);
        $this->assertSame('version_conflict', $r['json']['error']['details']['code']);
        $this->assertSame('v2', $r['json']['error']['details']['current']['title']);
        $this->assertSame('v2', $this->call('alice', 'GET', '/api/v1/appointments/' . $a['id'])['json']['data']['title'], 'rien écrasé');
        $this->assertSame(409, $this->call('alice', 'DELETE', '/api/v1/appointments/' . $a['id'], ['version' => 1])['status']);
    }

    public function testTimeConflictsAndOverride(): void
    {
        $this->create('alice');
        $r = $this->call('alice', 'POST', '/api/v1/appointments', $this->payload(['title' => 'chevauche', 'start' => '2026-11-18T09:30:00Z', 'end' => '2026-11-18T10:30:00Z']));
        $this->assertSame(409, $r['status']);
        $this->assertSame('time_conflict', $r['json']['error']['details']['code']);
        $this->assertSame('Réunion', $r['json']['error']['details']['conflicts'][0]['title']);
        // bord à bord : pas de conflit
        $this->create('alice', ['title' => 'enchaîné', 'start' => '2026-11-18T10:00:00Z', 'end' => '2026-11-18T11:00:00Z']);
        // autre calendrier : pas de conflit
        $this->pdo->exec('INSERT INTO calendar_permissions (calendar_id, user_id, can_read, can_create) VALUES (1, 2, 1, 1)');
        $this->create('alice', ['calendar_id' => 1, 'title' => 'forcé', 'start' => '2026-11-18T09:30:00Z', 'end' => '2026-11-18T10:30:00Z', 'ignore_conflicts' => true]);
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'C')");
        $this->create('alice', ['calendar_id' => 3, 'title' => 'autre cal', 'start' => '2026-11-18T09:00:00Z', 'end' => '2026-11-18T10:00:00Z']);
    }

    public function testCancelledDoesNotConflictAndEditingKeepsExistingOverlap(): void
    {
        $a = $this->create('alice');
        $this->call('alice', 'POST', '/api/v1/appointments/' . $a['id'] . '/cancel', ['version' => 1]);
        $this->create('alice', ['title' => 'même créneau']);
        // restaurer un rendez-vous annulé en le re-planifiant crée un conflit
        $r = $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 2, 'status' => 'scheduled']);
        $this->assertSame(200, $r['status'], 'changer seulement le statut ne revérifie pas les conflits');
        $b = $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 3, 'title' => 'renommé']);
        $this->assertSame(200, $b['status'], 'modifier le titre d\'un rendez-vous déjà en chevauchement reste possible');
        $m = $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 4, 'start' => '2026-11-18T09:15:00Z', 'end' => '2026-11-18T09:45:00Z']);
        $this->assertSame(409, $m['status'], 'déplacer revérifie');
    }

    public function testDeleteRestoreAndTrashListing(): void
    {
        $a = $this->create('alice');
        $this->assertSame(200, $this->call('alice', 'DELETE', '/api/v1/appointments/' . $a['id'], ['version' => 1])['status']);
        $this->assertSame(404, $this->call('alice', 'GET', '/api/v1/appointments/' . $a['id'])['status']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM appointments WHERE deleted_at IS NOT NULL')->fetchColumn(), 'suppression logique');
        $this->assertSame([], $this->call('alice', 'GET', '/api/v1/appointments')['json']['data']);
        $trash = $this->call('alice', 'GET', '/api/v1/appointments', [], ['deleted' => '1'])['json']['data'];
        $this->assertSame(1, count($trash));
        $this->assertSame(404, $this->call('bob', 'POST', '/api/v1/appointments/' . $a['id'] . '/restore')['status'], 'sans droit : 404');
        $r = $this->call('alice', 'POST', '/api/v1/appointments/' . $a['id'] . '/restore');
        $this->assertSame(200, $r['status']);
        $this->assertSame(3, $r['json']['data']['version']);
        $this->assertSame(1, count($this->call('alice', 'GET', '/api/v1/appointments')['json']['data']));
        $this->assertSame(404, $this->call('alice', 'POST', '/api/v1/appointments/' . $a['id'] . '/restore')['status'], 'déjà restauré');
        $changes = $this->pdo->query('SELECT change_type FROM sync_changes ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['created', 'deleted', 'restored'], $changes);
    }

    public function testDuplicateCancelCompleteReschedule(): void
    {
        $a = $this->create('alice');
        $d = $this->call('alice', 'POST', '/api/v1/appointments/' . $a['id'] . '/duplicate');
        $this->assertSame(201, $d['status']);
        $this->assertSame('Réunion (copie)', $d['json']['data']['title']);
        $this->assertSame($a['start'], $d['json']['data']['start']);
        $this->assertTrue($d['json']['data']['id'] !== $a['id'] && $d['json']['data']['uid'] !== $a['uid']);

        $c = $this->call('alice', 'POST', '/api/v1/appointments/' . $a['id'] . '/cancel', ['version' => 1]);
        $this->assertSame('cancelled', $c['json']['data']['status']);
        $k = $this->call('alice', 'POST', '/api/v1/appointments/' . $d['json']['data']['id'] . '/complete', ['version' => 1]);
        $this->assertSame('completed', $k['json']['data']['status']);
        $this->assertTrue($k['json']['data']['completed_at'] !== null);
        $this->assertSame(422, $this->call('alice', 'POST', '/api/v1/appointments/' . $a['id'] . '/cancel', [])['status'], 'version requise');

        $e = $this->create('alice', ['title' => 'à reporter', 'start' => '2026-11-19T09:00:00Z', 'end' => '2026-11-19T10:00:00Z']);
        $r = $this->call('alice', 'POST', '/api/v1/appointments/' . $e['id'] . '/reschedule', ['version' => 1, 'start' => '2026-11-26T09:00:00Z', 'end' => '2026-11-26T10:00:00Z']);
        $this->assertSame('2026-11-26T09:00:00Z', $r['json']['data']['start']);
        $this->assertSame('à reporter', $r['json']['data']['title']);
        $this->assertSame(422, $this->call('alice', 'POST', '/api/v1/appointments/' . $e['id'] . '/reschedule', ['version' => 2])['status']);
    }

    public function testMovingBetweenCalendarsNeedsRightsOnBoth(): void
    {
        $a = $this->create('alice');
        $this->assertSame(403, $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'calendar_id' => 2])['status'], 'pas de droit de création sur B');
        $this->pdo->exec('INSERT INTO calendar_permissions (calendar_id, user_id, can_read, can_create) VALUES (2, 1, 1, 1)');
        $r = $this->call('alice', 'PUT', '/api/v1/appointments/' . $a['id'], ['version' => 1, 'calendar_id' => 2]);
        $this->assertSame(200, $r['status']);
        $this->assertSame(2, $r['json']['data']['calendar_id']);
    }

    public function testListFiltersSortAndPagination(): void
    {
        foreach ([['Alpha', '2026-11-18T09:00:00Z', 'low', 'scheduled', 'Paris'], ['Beta', '2026-11-19T09:00:00Z', 'urgent', 'confirmed', 'Lyon'],
            ['Gamma 100%', '2026-11-20T09:00:00Z', 'normal', 'scheduled', 'Nice'], ['Delta', '2026-11-21T09:00:00Z', 'high', 'tentative', 'Paris']] as [$t, $s, $p, $st, $loc]) {
            $end = gmdate('Y-m-d\TH:i:s\Z', strtotime($s) + 3600);
            $this->create('alice', ['title' => $t, 'start' => $s, 'end' => $end, 'priority' => $p, 'status' => $st, 'location' => $loc]);
        }
        $this->create('bob', ['calendar_id' => 2, 'title' => 'Secret de Bob']);
        $list = fn (array $q) => array_column($this->call('alice', 'GET', '/api/v1/appointments', [], $q)['json']['data'], 'title');
        $this->assertSame(['Alpha', 'Beta', 'Gamma 100%', 'Delta'], $list([]));
        $this->assertSame(['Delta', 'Gamma 100%', 'Beta', 'Alpha'], $list(['sort' => 'start', 'dir' => 'desc']));
        $this->assertSame(['Beta', 'Delta', 'Gamma 100%', 'Alpha'], $list(['sort' => 'priority', 'dir' => 'desc']));
        $this->assertSame(['Alpha', 'Beta', 'Delta', 'Gamma 100%'], $list(['sort' => 'title']));
        $this->assertSame(['Gamma 100%'], $list(['q' => '100%']), 'LIKE échappé');
        $this->assertSame([], $list(['q' => '_']), 'le joker n\'est pas interprété');
        $this->assertSame(['Alpha', 'Delta'], $list(['q' => 'paris']));
        $this->assertSame(['Alpha', 'Gamma 100%'], $list(['status' => 'scheduled']));
        $this->assertSame(['Beta', 'Delta'], $list(['priority' => 'urgent,high']));
        $this->assertSame(['Beta', 'Gamma 100%'], $list(['from' => '2026-11-19', 'to' => '2026-11-21']));
        $this->assertSame([], $list(['q' => 'Secret']), 'autre utilisateur invisible');
        $p = $this->call('alice', 'GET', '/api/v1/appointments', [], ['per_page' => '3', 'page' => '2']);
        $this->assertSame(['Delta'], array_column($p['json']['data'], 'title'));
        $this->assertSame(['page' => 2, 'per_page' => 3, 'total' => 4, 'pages' => 2], $p['json']['meta']);
        foreach ([['sort' => 'password'], ['dir' => 'sideways'], ['page' => '0'], ['per_page' => '1000'], ['status' => 'nope'], ['created_by' => 'x']] as $bad) {
            $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/appointments', [], $bad)['status'], json_encode($bad));
        }
    }

    public function testMineFilterAndSqlInjectionAttempts(): void
    {
        $this->pdo->exec('INSERT INTO calendar_permissions (calendar_id, user_id, can_read, can_create) VALUES (2, 1, 1, 1)');
        $this->create('alice', ['title' => 'à moi']);
        $this->create('bob', ['calendar_id' => 2, 'title' => 'à bob']);
        $this->assertSame(['à moi'], array_column($this->call('alice', 'GET', '/api/v1/appointments', [], ['mine' => '1'])['json']['data'], 'title'));
        $r = $this->call('alice', 'GET', '/api/v1/appointments', [], ['q' => "' OR 1=1 --"]);
        $this->assertSame(200, $r['status']);
        $this->assertSame([], $r['json']['data']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn());
    }

    public function testCatalogEndpointsAreScoped(): void
    {
        $this->pdo->exec("INSERT INTO categories (name, color) VALUES ('Client', '#9333ea')");
        $this->pdo->exec("INSERT INTO contacts (owner_id, last_name, first_name) VALUES (1, 'Dupont', 'Marie'), (2, 'Caché', 'Bob')");
        $this->assertSame(['Client'], array_column($this->call('alice', 'GET', '/api/v1/categories')['json']['data'], 'name'));
        $this->assertSame(['Marie Dupont'], array_column($this->call('alice', 'GET', '/api/v1/contacts')['json']['data'], 'name'));
        $this->assertSame(404, $this->call('bob', 'POST', '/api/v1/appointments', $this->payload(['calendar_id' => 2, 'contact_id' => 1]))['status'] === 422 ? 404 : 0, 'contact d\'un autre refusé');
        $ok = $this->call('alice', 'POST', '/api/v1/appointments', $this->payload(['contact_id' => 1, 'category_id' => 1]));
        $this->assertSame(201, $ok['status']);
        $this->assertSame('Marie Dupont', $ok['json']['data']['contact']);
    }

    public function testSoftDeletedRowStillInDbAndMalformedIds(): void
    {
        $this->assertSame(404, $this->call('alice', 'GET', '/api/v1/appointments/abc')['status']);
        $this->assertSame(404, $this->call('alice', 'GET', '/api/v1/appointments/999')['status']);
        $this->assertSame(404, $this->call('alice', 'PUT', '/api/v1/appointments/999', ['version' => 1])['status']);
    }
}
