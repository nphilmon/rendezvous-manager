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
use App\Services\Transfer\Csv;
use Tests\DatabaseTestCase;

final class ReportsTest extends DatabaseTestCase
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
        foreach (['mgr' => 'manager', 'alice' => 'user', 'bob' => 'user'] as $n => $role) {
            $users->create(['role_code' => $role, 'username' => $n, 'email' => "$n@example.org", 'first_name' => ucfirst($n), 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("UPDATE users SET timezone = 'Europe/Paris'");
        $this->pdo->exec("INSERT INTO calendars (owner_id, name, visibility) VALUES (2,'=Cal Alice','private'), (3,'Cal Bob','private')");
        $this->pdo->exec("INSERT INTO categories (name, color) VALUES ('Pro', '#2563eb')");
    }

    private function call(string $as, string $path, array $query = []): Response
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token']];
        })();
        return Application::handle(new Request('GET', $path, $query, '', ['cookie' => $this->sessions[$as]['cookie']], '203.0.113.1'), $this->router);
    }

    private function add(int $cal, int $by, string $start, string $end, string $status = 'scheduled', int $allDay = 0, ?int $cat = null, string $prio = 'normal'): int
    {
        $this->pdo->prepare('INSERT INTO appointments (uid, calendar_id, created_by, category_id, title, start_utc, end_utc, status, all_day, priority) VALUES (UUID(),?,?,?,?,?,?,?,?,?)')
            ->execute([$cal, $by, $cat, 't', $start, $end, $status, $allDay, $prio]);
        return (int) $this->pdo->lastInsertId();
    }

    private function report(string $as, array $q = []): array
    {
        $r = $this->call($as, '/api/v1/reports', $q + ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertSame(200, $r->status, $r->body);
        return json_decode($r->body, true)['data'];
    }

    public function testTotalsDistributionsAndTimezone(): void
    {
        // 2 rendez-vous de 1 h et 2 h ; un terminé ; un annulé (heures exclues) ; une journée entière ; un hors période ; un supprimé
        $this->add(1, 2, '2026-11-02 09:00:00', '2026-11-02 10:00:00', 'scheduled', 0, 1, 'high');   // lundi 10 h Paris
        $this->add(1, 2, '2026-11-03 13:00:00', '2026-11-03 15:00:00', 'completed');                  // mardi 14 h
        $this->add(1, 2, '2026-11-04 08:00:00', '2026-11-04 09:00:00', 'cancelled');
        $this->add(1, 2, '2026-11-10 00:00:00', '2026-11-11 00:00:00', 'scheduled', 1);              // mardi, journée entière
        $this->add(1, 2, '2026-12-01 09:00:00', '2026-12-01 10:00:00');                               // hors période
        $x = $this->add(1, 2, '2026-11-05 09:00:00', '2026-11-05 10:00:00');
        $this->pdo->exec("UPDATE appointments SET deleted_at = NOW() WHERE id = $x");
        $this->add(2, 3, '2026-11-06 09:00:00', '2026-11-06 10:00:00'); // calendrier d'un autre
        $d = $this->report('mgr');
        $t = $d['totals'];
        $this->assertSame([5, 1, 1, 3, 4, 1], [$t['appointments'], $t['completed'], $t['cancelled'], $t['active'], $t['hours'], $t['all_day']], json_encode($t));
        $this->assertSame([20, 20], [$t['completed_rate'], $t['cancelled_rate']]);
        $this->assertSame(['scheduled' => 3, 'confirmed' => 0, 'tentative' => 0, 'completed' => 1, 'cancelled' => 1], $d['by_status']);
        $this->assertSame(1, $d['by_priority']['high']);
        $this->assertSame([['label' => 'Sans catégorie', 'count' => 4], ['label' => 'Pro', 'count' => 1]], $d['by_category']);
        $this->assertSame([['label' => '=Cal Alice', 'count' => 4], ['label' => 'Cal Bob', 'count' => 1]], $d['by_calendar']);
        $this->assertSame([['label' => 'Alice T', 'count' => 4], ['label' => 'Bob T', 'count' => 1]], $d['by_creator']);
        $this->assertSame(['Lundi' => 1, 'Mardi' => 2, 'Mercredi' => 1, 'Vendredi' => 1], array_filter(array_column($d['by_weekday'], 'count', 'label')));
        $byHour = array_filter(array_column($d['by_hour'], 'count'), fn ($n) => $n > 0);
        ksort($byHour);
        $this->assertSame([9 => 1, 10 => 2, 14 => 1], $byHour, 'heures locales (Paris, UTC+1) ; la journée entière n\'a pas d\'heure');
        $this->assertSame([['label' => '2026-11', 'count' => 5]], $d['by_month']);
        $this->assertSame('Europe/Paris', $d['period']['timezone']);
        // 23:30 UTC le 30 = 00:30 le 1er décembre à Paris : hors novembre
        $this->add(1, 2, '2026-11-30 23:30:00', '2026-11-30 23:45:00');
        $this->assertSame(5, $this->report('mgr')['totals']['appointments'], 'borne dans le fuseau de l\'utilisateur');
    }

    public function testSeriesAreExpandedAndPermissionsEnforced(): void
    {
        $id = $this->add(1, 2, '2026-11-02 08:00:00', '2026-11-02 09:00:00');
        $this->pdo->prepare("INSERT INTO appointment_recurrence (appointment_id, rrule, dtstart_local, timezone, occurrence_count) VALUES (?, 'FREQ=WEEKLY;COUNT=10', '2026-11-02 09:00:00', 'Europe/Paris', 10)")->execute([$id]);
        $this->assertSame(5, $this->report('mgr')['totals']['appointments'], '5 lundis en novembre 2026 (2, 9, 16, 23, 30)');
        $this->assertSame(403, $this->call('alice', '/api/v1/reports')->status, 'rôle « utilisateur » : pas de rapports');
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/reports', [], '', [], '203.0.113.1'), $this->router)->status);
        $m = $this->report('mgr');
        $this->assertSame(5, $m['totals']['appointments'], 'responsable : tous les calendriers (manage_all)');
        $this->assertSame(2, $m['totals']['calendars']);
        foreach ([['from' => 'hier'], ['from' => '2026-02-31'], ['to' => '2026-13-01'], ['from' => '2026-11-10', 'to' => '2026-11-01'], ['from' => '2025-01-01', 'to' => '2026-12-31'], ['format' => 'xml'], ['from' => ['x']]] as $bad) {
            $this->assertSame(422, $this->call('mgr', '/api/v1/reports', $bad)->status, json_encode($bad));
        }
        $this->assertSame(200, $this->call('mgr', '/api/v1/reports', ['from' => '2026-01-01', 'to' => '2026-12-31'])->status, '365 jours : accepté');
    }

    public function testCsvExportIsProtectedAndAudited(): void
    {
        $this->add(1, 2, '2026-11-02 09:00:00', '2026-11-02 10:00:00');
        $r = $this->call('mgr', '/api/v1/reports', ['from' => '2026-11-01', 'to' => '2026-11-30', 'format' => 'csv']);
        $this->assertSame(200, $r->status);
        $this->assertTrue(str_starts_with($r->headers['Content-Type'], 'text/csv') && str_contains($r->headers['Content-Disposition'], 'rapport-2026-11-01_2026-11-30.csv'));
        $rows = Csv::decode($r->body);
        $this->assertSame(['Rubrique', 'Libellé', 'Nombre'], $rows[0]);
        $cal = array_values(array_filter($rows, fn ($x) => $x[0] === 'Calendrier'));
        $this->assertSame("'=Cal Alice", $cal[0][1], 'formule neutralisée dans l\'export');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'data.export' AND details LIKE '%report%'")->fetchColumn());
    }
}
