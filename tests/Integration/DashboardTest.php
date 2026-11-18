<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Application;
use App\Core\Request;
use App\Core\Router;
use App\Repositories\AppointmentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\StatsRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\DashboardService;
use App\Services\PermissionService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\DatabaseTestCase;

final class DashboardTest extends DatabaseTestCase
{
    private UserRepository $users;
    private DashboardService $svc;
    private DateTimeImmutable $now;

    public function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        foreach (['alice', 'bob'] as $n) {
            $this->users->create(['role_code' => 'user', 'username' => $n, 'email' => "$n@example.org", 'first_name' => $n, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("UPDATE users SET timezone='Europe/Paris'");
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'A'), (2,'B')");
        $this->svc = new DashboardService($this->pdo, new PermissionService($this->pdo), new AppointmentRepository($this->pdo), new StatsRepository($this->pdo), new NotificationRepository($this->pdo));
        $this->now = new DateTimeImmutable('2026-11-18 10:00:00', new DateTimeZone('UTC')); // mercredi
    }

    private function add(int $cal, int $user, string $title, string $start, string $end, string $status = 'scheduled', ?string $deleted = null, int $allDay = 0, ?int $category = null): int
    {
        $this->pdo->prepare('INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc, status, deleted_at, all_day, category_id) VALUES (UUID(),?,?,?,?,?,?,?,?,?)')
            ->execute([$cal, $user, $title, $start, $end, $status, $deleted, $allDay, $category]);
        return (int) $this->pdo->lastInsertId();
    }

    private function build(int $user, string $period = 'month'): array
    {
        return $this->svc->build($this->users->findAuthUser($user), $period, $this->now);
    }

    private function seed(): void
    {
        $this->add(1, 1, 'aujourd\'hui', '2026-11-18 14:00:00', '2026-11-18 15:00:00');
        $this->add(1, 1, 'lundi', '2026-11-16 08:00:00', '2026-11-16 09:00:00', 'completed');
        $this->add(1, 1, 'semaine suivante', '2026-11-25 08:00:00', '2026-11-25 09:00:00');
        $this->add(1, 1, 'annulé futur', '2026-11-26 08:00:00', '2026-11-26 09:00:00', 'cancelled');
        $this->add(1, 1, 'supprimé', '2026-11-18 16:00:00', '2026-11-18 17:00:00', 'scheduled', '2026-11-01 00:00:00');
        $this->add(2, 2, 'autre calendrier', '2026-11-18 14:00:00', '2026-11-18 15:00:00');
    }

    public function testCountsAreRealAndPermissionScoped(): void
    {
        $this->seed();
        $d = $this->build(1);
        $this->assertSame(4, $d['counts']['total'], 'total = lignes non supprimées du calendrier autorisé');
        $this->assertSame(1, $d['counts']['today']);
        $this->assertSame(1, $d['counts']['week'], 'le terminé du lundi n\'est pas actif');
        $this->assertSame(2, $d['counts']['month']);
        $this->assertSame(2, $d['counts']['upcoming'], 'annulé exclu, terminé exclu');
        $this->assertSame(1, $d['period']['completed']);
        $this->assertSame(1, $d['period']['cancelled']);
        $this->assertSame(1, $this->build(2)['counts']['total'], 'Bob ne voit que son calendrier');
        $this->assertSame(1, $this->build(2)['counts']['week']);
    }

    public function testNextAppointmentIgnoresCancelledCompletedAndForeign(): void
    {
        $this->seed();
        $this->assertSame('aujourd\'hui', $this->build(1)['next']['title']);
        $this->pdo->exec("UPDATE appointments SET status='cancelled' WHERE title='aujourd''hui'");
        $this->assertSame('semaine suivante', $this->build(1)['next']['title']);
        $this->pdo->exec("UPDATE appointments SET status='completed' WHERE title='semaine suivante'");
        $this->assertSame(null, $this->build(1)['next']);
    }

    public function testTimezoneBoundariesUseUserTimezone(): void
    {
        $this->seed();
        // 23:30 UTC le 17 = 00:30 le 18 à Paris → « aujourd'hui »
        $this->pdo->exec("UPDATE appointments SET start_utc='2026-11-17 23:30:00', end_utc='2026-11-17 23:45:00' WHERE title='lundi'");
        $this->pdo->exec("UPDATE appointments SET status='scheduled' WHERE title='lundi'");
        $this->assertSame(2, $this->build(1)['counts']['today']);
        $this->pdo->exec("UPDATE users SET timezone='UTC'");
        $this->assertSame(1, $this->build(1)['counts']['today']);
    }

    public function testRecurringSeriesAreExpandedIntoOccurrences(): void
    {
        $id = $this->add(1, 1, 'quotidien', '2026-11-16 08:00:00', '2026-11-16 09:00:00');
        $this->pdo->prepare("INSERT INTO appointment_recurrence (appointment_id, rrule, dtstart_local, timezone, occurrence_count) VALUES (?, 'FREQ=DAILY;COUNT=10', '2026-11-16 09:00:00', 'Europe/Paris', 10)")->execute([$id]);
        $d = $this->build(1);
        $this->assertSame(1, $d['counts']['total'], 'une série = un rendez-vous');
        $this->assertSame(1, $d['counts']['today']);
        $this->assertSame(7, $d['counts']['week'], 'lun 16 … dim 22 : 7 occurrences');
        $this->assertSame(10, $d['counts']['month']);
        $this->assertSame(7, $d['counts']['upcoming'], 'occurrences du 19 au 25 (celle du 18 à 08:00Z est passée)');
    }

    public function testAllDayAppointmentCountsOnItsLocalDate(): void
    {
        $this->add(1, 1, 'férié', '2026-11-18 00:00:00', '2026-11-19 00:00:00', 'scheduled', null, 1);
        $d = $this->build(1);
        $this->assertSame(1, $d['counts']['today']);
        $this->assertSame(0, $d['counts']['upcoming'], 'la journée entière d\'aujourd\'hui est en cours, pas à venir');
        $this->assertSame(1, $d['by_day'][0]['count']);
        $this->assertSame(0, $d['by_day'][1]['count']);
    }

    public function testDistributionsAndLoad(): void
    {
        $this->pdo->exec("INSERT INTO categories (name, color) VALUES ('Pro', '#2563eb')");
        $this->add(1, 1, 'a', '2026-11-18 14:00:00', '2026-11-18 15:00:00', 'scheduled', null, 0, 1);
        $this->add(1, 1, 'b', '2026-11-19 14:00:00', '2026-11-19 15:00:00', 'confirmed', null, 0, 1);
        $this->add(1, 1, 'c', '2026-11-20 14:00:00', '2026-11-20 15:00:00', 'confirmed');
        $this->add(1, 1, 'd', '2026-11-21 14:00:00', '2026-11-21 15:00:00', 'cancelled', null, 0, 1);
        $d = $this->build(1);
        $by = array_column($d['by_status'], 'count', 'status');
        $this->assertSame(['scheduled' => 1, 'confirmed' => 2, 'tentative' => 0, 'completed' => 0, 'cancelled' => 1], $by);
        $cat = array_column($d['by_category'], 'count', 'name');
        $this->assertSame(['Pro' => 2, 'Sans catégorie' => 1], $cat, 'annulés exclus des catégories');
        $this->assertSame(14, count($d['by_day']));
        $this->assertSame('2026-11-18', $d['by_day'][0]['date']);
        $this->assertSame([1, 1, 1, 0], array_slice(array_column($d['by_day'], 'count'), 0, 4));
    }

    public function testPeriodsAndValidationThroughApi(): void
    {
        $router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($router, $this->pdo);
        $auth = new AuthService($this->pdo, $this->users, new AuditLogger($this->pdo));
        $call = function (?string $cookie, string $period) use ($router): array {
            $res = Application::handle(new Request('GET', '/api/v1/dashboard', ['period' => $period], '', $cookie ? ['cookie' => $cookie] : [], '203.0.113.1'), $router);
            return [$res->status, json_decode($res->body, true) ?? []];
        };
        $this->assertSame(401, $call(null, 'month')[0]);
        $cookie = 'rvm_session=' . $auth->login('alice', 'Correct-Horse-9', '203.0.113.1', 't')['token'];
        [$st, $j] = $call($cookie, 'year');
        $this->assertSame(200, $st);
        $this->assertSame('year', $j['data']['period']['key']);
        $this->assertSame(422, $call($cookie, 'decade')[0]);
    }
}
