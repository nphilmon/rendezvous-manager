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

/** Centre de notifications et réglages de notification (API). */
final class NotificationApiTest extends DatabaseTestCase
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
        foreach (['alice', 'bob'] as $n) {
            $users->create(['role_code' => 'user', 'username' => $n, 'email' => "$n@example.org", 'first_name' => $n, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1, 'A')");
        $this->pdo->exec("INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc) VALUES (UUID(), 1, 1, 'RV', '2026-11-18 10:00:00', '2026-11-18 11:00:00')");
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

    /** @return list<int> */
    private function seed(int $user, int $n, int $readFirst = 0): array
    {
        $ids = [];
        $ins = $this->pdo->prepare("INSERT INTO notification_deliveries (appointment_id, user_id, channel, occurrence_start_utc, scheduled_at, status, sent_at, read_at, title, body) VALUES (1, ?, 'inapp', '2026-11-18 10:00:00', ?, 'sent', ?, ?, ?, 'corps')");
        for ($i = 1; $i <= $n; $i++) {
            $at = gmdate('Y-m-d H:i:s', strtotime('2026-11-18 09:00:00') + $i * 60);
            $ins->execute([$user, $at, $at, $i <= $readFirst ? $at : null, "N$i"]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }
        return $ids;
    }

    public function testListFiltersPaginationAndOrder(): void
    {
        $this->seed(1, 5, 2); // N1, N2 lues
        $this->pdo->exec("INSERT INTO notification_deliveries (user_id, channel, occurrence_start_utc, scheduled_at, status, title) VALUES (1, 'push', '2026-11-18 10:00:00', '2026-11-18 09:00:00', 'sent', 'push'), (1, 'inapp', '2026-11-18 10:00:00', '2026-11-18 09:00:00', 'pending', 'pas encore'), (1, 'inapp', '2026-11-18 10:00:00', '2026-11-18 09:00:00', 'cancelled', 'annulée')");
        $titles = fn (array $q) => array_column($this->call('alice', 'GET', '/api/v1/notifications', [], $q)['json']['data'], 'title');
        $this->assertSame(['N5', 'N4', 'N3', 'N2', 'N1'], $titles([]), 'historique : plus récentes d\'abord, seulement « dans l\'application » envoyées');
        $this->assertSame(['N5', 'N4', 'N3'], $titles(['filter' => 'unread']));
        $this->assertSame(['N2', 'N1'], $titles(['filter' => 'read']));
        $p = $this->call('alice', 'GET', '/api/v1/notifications', [], ['per_page' => '2', 'page' => '2']);
        $this->assertSame(['N3', 'N2'], array_column($p['json']['data'], 'title'));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 5, 'pages' => 3, 'unread' => 3], $p['json']['meta']);
        $this->assertSame([], $this->call('bob', 'GET', '/api/v1/notifications')['json']['data'], 'chacun ne voit que les siennes');
        foreach ([['filter' => 'x'], ['page' => '0'], ['per_page' => '500']] as $bad) {
            $this->assertSame(422, $this->call('alice', 'GET', '/api/v1/notifications', [], $bad)['status'], json_encode($bad));
        }
        $first = $this->call('alice', 'GET', '/api/v1/notifications')['json']['data'][0];
        $this->assertSame(1, $first['appointment_id']);
        $this->assertTrue($first['appointment_exists'] && $first['occurrence_start'] === '2026-11-18T10:00:00Z' && $first['read'] === false);
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/notifications'), $this->router)->status);
    }

    public function testMarkReadUnreadAllAndIsolation(): void
    {
        $ids = $this->seed(1, 3);
        $other = $this->seed(2, 1)[0];
        $this->assertSame(3, $this->call('alice', 'GET', '/api/v1/notifications/count')['json']['unread']);
        $r = $this->call('alice', 'POST', '/api/v1/notifications/' . $ids[0] . '/read');
        $this->assertSame([200, 2], [$r['status'], $r['json']['unread']]);
        $this->assertSame(200, $this->call('alice', 'POST', '/api/v1/notifications/' . $ids[0] . '/read')['status'], 'idempotent');
        $this->assertSame(3, $this->call('alice', 'POST', '/api/v1/notifications/' . $ids[0] . '/unread')['json']['unread']);
        $this->assertSame(404, $this->call('alice', 'POST', '/api/v1/notifications/' . $other . '/read')['status'], 'notification d\'un autre utilisateur');
        $this->assertSame(404, $this->call('alice', 'POST', '/api/v1/notifications/999/read')['status']);
        $this->assertSame(1, $this->call('bob', 'GET', '/api/v1/notifications/count')['json']['unread'], 'rien ne change pour Bob');
        $a = $this->call('alice', 'POST', '/api/v1/notifications/read-all');
        $this->assertSame([3, 0], [$a['json']['updated'], $a['json']['unread']]);
        $this->assertSame(1, $this->call('bob', 'GET', '/api/v1/notifications/count')['json']['unread']);
        $this->assertSame(403, $this->call('alice', 'POST', '/api/v1/notifications/read-all', [], [], false)['status'], 'CSRF requis');
    }

    public function testUpcomingListsFutureRemindersOnly(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 2 * 86400);
        $this->pdo->exec("INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc) VALUES (UUID(), 1, 1, 'Bientôt', '$future', DATE_ADD('$future', INTERVAL 1 HOUR))");
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO reminders (appointment_id, user_id, offset_minutes, channel) VALUES ($id, 1, 30, 'inapp'), (1, 1, 5, 'inapp')");
        $r = $this->call('alice', 'GET', '/api/v1/notifications/upcoming');
        $this->assertSame(200, $r['status']);
        $this->assertSame(1, count($r['json']['data']), 'le rendez-vous passé est ignoré');
        $this->assertSame([$id, 30, 'Rappel : Bientôt'], [$r['json']['data'][0]['appointment_id'], $r['json']['data'][0]['offset_minutes'], $r['json']['data'][0]['title']]);
        $this->assertSame([], $this->call('bob', 'GET', '/api/v1/notifications/upcoming')['json']['data']);
    }

    public function testSettingsDefaultsValidationAndPersistence(): void
    {
        $g = $this->call('alice', 'GET', '/api/v1/settings');
        $this->assertSame(200, $g['status']);
        $this->assertSame(['enabled' => true, 'channels' => ['inapp'], 'privacy' => 'full', 'missed' => 'deliver', 'quiet_start' => null, 'quiet_end' => null, 'default_reminder' => null], $g['json']['notifications']);
        $this->assertSame(['inapp'], $g['json']['meta']['channels_available']);
        foreach ([['privacy' => 'public'], ['enabled' => 'oui'], ['quiet_start' => '25:00'], ['quiet_start' => '22:00'], ['channels' => ['push']], ['channels' => ['inapp', 'sms']], ['channels' => []],
            ['default_reminder' => -5], ['default_reminder' => 999999], ['default_reminder' => '15'], ['inconnu' => 1], ['missed' => 'maybe']] as $bad) {
            $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => $bad])['status'], json_encode($bad));
        }
        $u = $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['privacy' => 'minimal', 'quiet_start' => '22:00', 'quiet_end' => '07:30', 'default_reminder' => 15, 'missed' => 'skip']]);
        $this->assertSame(200, $u['status'], json_encode($u['json']));
        $this->assertSame(['minimal', '22:00', 15, 'skip'], [$u['json']['notifications']['privacy'], $u['json']['notifications']['quiet_start'], $u['json']['notifications']['default_reminder'], $u['json']['notifications']['missed']]);
        $this->assertSame('full', $this->call('bob', 'GET', '/api/v1/settings')['json']['notifications']['privacy'], 'réglages propres à chaque utilisateur');
        $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/settings', ['x' => 1])['status']);
        $c = $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['quiet_start' => null, 'quiet_end' => null, 'default_reminder' => null]]);
        $this->assertSame([null, null, null], [$c['json']['notifications']['quiet_start'], $c['json']['notifications']['quiet_end'], $c['json']['notifications']['default_reminder']]);
        $this->assertSame(403, $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['enabled' => false]], [], false)['status']);
    }

    public function testReminderChannelsFollowSettingsWhenSavingAnAppointment(): void
    {
        $r = $this->call('alice', 'POST', '/api/v1/appointments', ['calendar_id' => 1, 'title' => 'Avec rappel', 'start' => '2026-12-01T09:00:00Z', 'end' => '2026-12-01T10:00:00Z', 'reminder_minutes' => 15]);
        $this->assertSame(201, $r['status'], json_encode($r['json']));
        $this->assertSame(15, $r['json']['data']['reminder_minutes']);
        $this->assertSame(['inapp'], $this->pdo->query("SELECT channel FROM reminders WHERE appointment_id = {$r['json']['data']['id']}")->fetchAll(\PDO::FETCH_COLUMN));
        $this->pdo->exec("INSERT INTO settings (user_id, setting_key, setting_value) VALUES (1, 'notify.channels', '[\"inapp\",\"push\"]')");
        $r2 = $this->call('alice', 'PUT', '/api/v1/appointments/' . $r['json']['data']['id'], ['version' => 1, 'reminder_minutes' => 60]);
        $this->assertSame(200, $r2['status']);
        $this->assertSame(['inapp', 'push'], $this->pdo->query("SELECT channel FROM reminders WHERE appointment_id = {$r['json']['data']['id']} ORDER BY channel")->fetchAll(\PDO::FETCH_COLUMN), 'une ligne par canal activé');
        $this->assertSame(60, $r2['json']['data']['reminder_minutes']);
    }
}
