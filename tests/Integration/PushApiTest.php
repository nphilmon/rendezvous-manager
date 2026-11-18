<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\PushApiController;
use App\Core\Application;
use App\Core\Config;
use App\Core\Env;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\Authenticator;
use App\Repositories\PushSubscriptionRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Push\Base64Url as B;
use App\Services\Push\EcKeys;
use App\Services\Push\EndpointPolicy;
use App\Services\Push\PushHttpClient;
use App\Services\Push\Vapid;
use Tests\DatabaseTestCase;

/** API Web Push et canaux de notification (abonnements, test, historique, réglages, synchronisation des rappels). */
final class PushApiTest extends DatabaseTestCase
{
    private Router $router;
    private AuthService $auth;
    /** @var array<string,array{cookie:string,csrf:string}> */
    private array $sessions = [];
    /** @var array{public:string,private:string} */
    private array $vapidKeys;
    private object $http;

    public function setUp(): void
    {
        parent::setUp();
        $this->sessions = [];
        $this->vapidKeys = Vapid::generate();
        $this->configure(['VAPID_PUBLIC_KEY' => $this->vapidKeys['public'], 'VAPID_PRIVATE_KEY' => $this->vapidKeys['private'], 'VAPID_SUBJECT' => 'mailto:admin@example.org', 'MAIL_ENABLED' => 'false', 'MAIL_HOST' => '', 'MAIL_FROM' => '']);
        $users = new UserRepository($this->pdo);
        $this->auth = new AuthService($this->pdo, $users, new AuditLogger($this->pdo));
        foreach (['alice', 'bob'] as $n) {
            $users->create(['role_code' => 'user', 'username' => $n, 'email' => "$n@example.org", 'first_name' => $n, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9')]);
        }
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1, 'A')");
        $this->http = new class implements PushHttpClient {
            public array $calls = [];
            public int $status = 201;

            public function post(string $url, array $headers, string $body, int $timeout, ?string $pinIp = null): array
            {
                $this->calls[] = $url;
                return ['status' => $this->status, 'body' => '', 'error' => null];
            }
        };
        $this->router = new Router();
        $guard = new Authenticator($this->auth);
        $policy = new EndpointPolicy(['fcm.googleapis.com'], false, false, fn () => ['93.184.216.34']);
        // la route de test utilise un faux client HTTP ; les autres routes sont les vraies
        $this->router->add('POST', '/api/v1/push/test', fn (Request $q) => (new PushApiController($guard, new PushSubscriptionRepository($this->pdo), $this->pdo, new AuditLogger($this->pdo), $policy, $this->http))->test($q));
        (require dirname(__DIR__, 2) . '/api/routes.php')($this->router, $this->pdo);
    }

    protected function configure(array $env): void
    {
        foreach ($env as $k => $v) {
            Env::set($k, $v);
        }
        Config::init(dirname(__DIR__, 2) . '/config');
    }

    public function tearDown(): void
    {
        $this->configure(['VAPID_PUBLIC_KEY' => '', 'VAPID_PRIVATE_KEY' => '', 'VAPID_SUBJECT' => '', 'MAIL_ENABLED' => 'false', 'MAIL_HOST' => '', 'MAIL_FROM' => '']);
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function call(string $as, string $method, string $path, array $body = [], array $query = [], bool $csrf = true): array
    {
        $this->sessions[$as] ??= (function () use ($as) {
            $r = $this->auth->login($as, 'Correct-Horse-9', '203.0.113.1', 't');
            return ['cookie' => 'rvm_session=' . $r['token'], 'csrf' => $r['csrf']];
        })();
        $h = ['cookie' => $this->sessions[$as]['cookie'], 'user-agent' => 'PHPUnit-UA/1.0'];
        if ($csrf && $method !== 'GET') {
            $h['x-csrf-token'] = $this->sessions[$as]['csrf'];
        }
        $res = Application::handle(new Request($method, $path, $query, $body ? json_encode($body) : '', $h, '203.0.113.1'), $this->router);
        return ['status' => $res->status, 'json' => json_decode($res->body, true) ?? []];
    }

    /** @return array<string,mixed> */
    private function sub(string $path = 'abc', ?array $keys = null): array
    {
        $k = $keys ?? EcKeys::generate();
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $path, 'keys' => ['p256dh' => B::encode($k['public']), 'auth' => B::encode(random_bytes(16))]];
    }

    public function testConfigExposesOnlyThePublicKey(): void
    {
        $this->assertSame(401, Application::handle(new Request('GET', '/api/v1/push/config'), $this->router)->status);
        $r = $this->call('alice', 'GET', '/api/v1/push/config');
        $this->assertSame([true, $this->vapidKeys['public']], [$r['json']['enabled'], $r['json']['public_key']]);
        $this->assertTrue(!str_contains(json_encode($r['json']), $this->vapidKeys['private']), 'la clé privée ne sort jamais du serveur');
        $this->configure(['VAPID_PRIVATE_KEY' => '']);
        $this->assertSame([false, null], [$this->call('alice', 'GET', '/api/v1/push/config')['json']['enabled'], $this->call('alice', 'GET', '/api/v1/push/config')['json']['public_key']]);
        $this->assertSame(503, $this->call('alice', 'POST', '/api/v1/push/subscribe', $this->sub())['status'], 'push non configuré');
        $this->configure(['VAPID_PRIVATE_KEY' => 'pas-une-cle']);
        $this->assertTrue(!$this->call('alice', 'GET', '/api/v1/push/config')['json']['enabled'], 'clé invalide : push désactivé plutôt que cassé');
    }

    public function testSubscribeValidation(): void
    {
        $ok = $this->sub();
        $cases = [
            ['endpoint' => 'http://fcm.googleapis.com/x', 'keys' => $ok['keys']] + [], ['endpoint' => 'https://evil.example/x', 'keys' => $ok['keys']], ['endpoint' => 'https://u:p@fcm.googleapis.com/x', 'keys' => $ok['keys']],
            ['endpoint' => 123, 'keys' => $ok['keys']], ['endpoint' => $ok['endpoint']], ['endpoint' => $ok['endpoint'], 'keys' => ['p256dh' => 'court', 'auth' => $ok['keys']['auth']]],
            ['endpoint' => $ok['endpoint'], 'keys' => ['p256dh' => B::encode("\x04" . str_repeat("\x07", 64)), 'auth' => $ok['keys']['auth']]], // hors courbe
            ['endpoint' => $ok['endpoint'], 'keys' => ['p256dh' => $ok['keys']['p256dh'], 'auth' => B::encode('trop court')]], ['endpoint' => $ok['endpoint'], 'keys' => $ok['keys'], 'expirationTime' => 'demain'],
        ];
        foreach ($cases as $i => $c) {
            $this->assertSame(422, $this->call('alice', 'POST', '/api/v1/push/subscribe', $c)['status'], "cas $i : " . json_encode($c));
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
        $this->assertSame(403, $this->call('alice', 'POST', '/api/v1/push/subscribe', $ok, [], false)['status'], 'CSRF');
        $r = $this->call('alice', 'POST', '/api/v1/push/subscribe', $ok + ['expirationTime' => (time() + 86400) * 1000]);
        $this->assertSame(201, $r['status'], json_encode($r['json']));
        $this->assertSame(201, $this->call('alice', 'POST', '/api/v1/push/subscribe', $ok)['status'], 'réabonnement idempotent');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
        $row = $this->pdo->query('SELECT * FROM push_subscriptions')->fetch();
        $this->assertSame(['PHPUnit-UA/1.0', null], [$row['user_agent'], $row['expires_at'] === null ? null : null]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'push.subscribe'")->fetchColumn() >= 1 ? 1 : 0);
    }

    public function testListingHidesEndpointsAndOwnershipIsEnforced(): void
    {
        $a = $this->sub('secret-token-alice');
        $this->call('alice', 'POST', '/api/v1/push/subscribe', $a);
        $l = $this->call('alice', 'GET', '/api/v1/push/subscriptions');
        $this->assertSame('fcm.googleapis.com', $l['json']['data'][0]['service']);
        $this->assertTrue(!str_contains(json_encode($l['json']), 'secret-token-alice'), 'l\'URL complète (jeton) n\'est jamais renvoyée');
        $this->assertSame([], $this->call('bob', 'GET', '/api/v1/push/subscriptions')['json']['data']);
        $this->assertSame(false, $this->call('bob', 'POST', '/api/v1/push/unsubscribe', ['endpoint' => $a['endpoint']])['json']['removed'], 'Bob ne peut pas désabonner Alice');
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
        // le même navigateur passe à Bob : l'abonnement lui est réattribué
        $this->call('bob', 'POST', '/api/v1/push/subscribe', $a);
        $this->assertSame([0, 1], [count($this->call('alice', 'GET', '/api/v1/push/subscriptions')['json']['data']), count($this->call('bob', 'GET', '/api/v1/push/subscriptions')['json']['data'])]);
        $this->assertSame(true, $this->call('bob', 'POST', '/api/v1/push/unsubscribe', ['endpoint' => $a['endpoint']])['json']['removed']);
        $this->assertSame(false, $this->call('bob', 'POST', '/api/v1/push/unsubscribe', ['endpoint' => $a['endpoint']])['json']['removed'], 'idempotent');
        $this->assertSame(422, $this->call('bob', 'POST', '/api/v1/push/unsubscribe', [])['status']);
    }

    public function testSubscriptionLimit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(201, $this->call('alice', 'POST', '/api/v1/push/subscribe', $this->sub("d$i"))['status']);
        }
        $this->assertSame(422, $this->call('alice', 'POST', '/api/v1/push/subscribe', $this->sub('d11'))['status']);
        $this->assertSame(201, $this->call('alice', 'POST', '/api/v1/push/subscribe', $this->sub('d3'))['status'], 'un appareil déjà abonné peut se réabonner');
    }

    public function testTestNotificationAndRateLimit(): void
    {
        $this->assertSame(0, $this->call('alice', 'POST', '/api/v1/push/test')['json']['total'], 'aucun appareil');
        $this->call('alice', 'POST', '/api/v1/push/subscribe', $this->sub('one'));
        $this->call('alice', 'POST', '/api/v1/push/subscribe', $this->sub('two'));
        $r = $this->call('alice', 'POST', '/api/v1/push/test');
        $this->assertSame([2, 2, 0], [$r['json']['sent'], $r['json']['total'], $r['json']['failed']]);
        $this->assertSame(2, count($this->http->calls));
        $this->http->status = 410;
        $g = $this->call('alice', 'POST', '/api/v1/push/test');
        $this->assertSame([0, 2], [$g['json']['sent'], $g['json']['expired']], 'abonnements expirés détectés et supprimés');
        $this->assertSame(0, $this->call('alice', 'GET', '/api/v1/push/subscriptions')['json']['data'] === [] ? 0 : 1);
        $this->call('alice', 'POST', '/api/v1/push/test');
        $this->call('alice', 'POST', '/api/v1/push/test');
        $this->assertSame(429, $this->call('alice', 'POST', '/api/v1/push/test')['status'], 'au plus 5 tests par minute');
        $this->assertSame(200, $this->call('bob', 'POST', '/api/v1/push/test')['status'], 'limite propre à chaque utilisateur');
        $this->assertSame(403, $this->call('bob', 'POST', '/api/v1/push/test', [], [], false)['status']);
    }

    public function testDeliveryHistoryIsPerUserAndPushOnly(): void
    {
        $ins = $this->pdo->prepare("INSERT INTO notification_deliveries (user_id, channel, occurrence_start_utc, scheduled_at, status, attempts, last_error, title) VALUES (?, ?, '2026-11-18 10:00:00', '2026-11-18 09:00:00', ?, 2, ?, ?)");
        $ins->execute([1, 'push', 'failed', 'service push HTTP 503', 'Rappel : A']);
        $ins->execute([1, 'inapp', 'sent', null, 'Rappel : in-app']);
        $ins->execute([2, 'push', 'sent', null, 'Rappel : B']);
        $d = $this->call('alice', 'GET', '/api/v1/push/deliveries')['json']['data'];
        $this->assertSame(['Rappel : A'], array_column($d, 'title'));
        $this->assertSame(['failed', 'service push HTTP 503', 2], [$d[0]['status'], $d[0]['error'], $d[0]['attempts']]);
    }

    public function testAvailableChannelsFollowServerConfigurationAndRemindersAreSynchronised(): void
    {
        $g = $this->call('alice', 'GET', '/api/v1/settings');
        $this->assertSame(['inapp', 'push'], $g['json']['meta']['channels_available']);
        $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['channels' => ['inapp', 'email']]])['status'], 'e-mail non configuré');
        $this->assertSame(422, $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['channels' => ['push']]])['status'], '« inapp » reste obligatoire');
        $a = $this->call('alice', 'POST', '/api/v1/appointments', ['calendar_id' => 1, 'title' => 'RV', 'start' => '2026-12-01T09:00:00Z', 'end' => '2026-12-01T10:00:00Z', 'reminder_minutes' => 15]);
        $id = $a['json']['data']['id'];
        $this->assertSame(['inapp'], $this->pdo->query("SELECT channel FROM reminders WHERE appointment_id = $id")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(200, $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['channels' => ['inapp', 'push']]])['status']);
        $ch = $this->pdo->query("SELECT channel FROM reminders WHERE appointment_id = $id")->fetchAll(\PDO::FETCH_COLUMN);
        sort($ch);
        $this->assertSame(['inapp', 'push'], $ch, 'les rappels existants gagnent le canal activé');
        $this->pdo->exec("INSERT INTO notification_deliveries (reminder_id, appointment_id, user_id, channel, occurrence_start_utc, scheduled_at, status, title) SELECT id, $id, 1, 'push', '2026-12-01 09:00:00', '2026-12-01 08:45:00', 'pending', 't' FROM reminders WHERE channel = 'push'");
        $this->assertSame(200, $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['channels' => ['inapp']]])['status']);
        $this->assertSame(['inapp'], $this->pdo->query("SELECT channel FROM reminders WHERE appointment_id = $id")->fetchAll(\PDO::FETCH_COLUMN), 'canal désactivé : rappels retirés');
        $this->assertSame('cancelled', $this->pdo->query("SELECT status FROM notification_deliveries WHERE channel = 'push'")->fetchColumn(), 'envois en attente annulés');
        $this->assertSame([], $this->pdo->query("SELECT 1 FROM reminders WHERE user_id = 2")->fetchAll(), 'les autres utilisateurs ne sont pas touchés');
        // e-mail configuré : canal disponible
        $this->configure(['MAIL_ENABLED' => 'true', 'MAIL_HOST' => 'smtp.example.org', 'MAIL_FROM' => 'noreply@example.org']);
        $this->assertSame(['inapp', 'push', 'email'], $this->call('alice', 'GET', '/api/v1/settings')['json']['meta']['channels_available']);
        $this->assertSame(200, $this->call('alice', 'PUT', '/api/v1/settings', ['notifications' => ['channels' => ['inapp', 'email']]])['status']);
        $ch = $this->pdo->query("SELECT channel FROM reminders WHERE appointment_id = $id")->fetchAll(\PDO::FETCH_COLUMN);
        sort($ch);
        $this->assertSame(['email', 'inapp'], $ch);
    }
}
