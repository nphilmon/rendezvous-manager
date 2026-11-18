<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\PushSubscriptionRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\Notifications\InAppSender;
use App\Services\Notifications\NotificationEngine;
use App\Services\Push\Base64Url as B;
use App\Services\Push\CurlPushHttpClient;
use App\Services\Push\EcKeys;
use App\Services\Push\EndpointPolicy;
use App\Services\Push\PushHttpClient;
use App\Services\Push\PushSender;
use App\Services\Push\Vapid;
use App\Services\Push\WebPushEncryptor;
use DateTimeImmutable;
use DateTimeZone;
use Tests\DatabaseTestCase;

/** Web Push : envoi chiffré, réponses des services push, abonnements, intégration au moteur et service HTTP réel simulé. */
final class PushDeliveryTest extends DatabaseTestCase
{
    private PushSubscriptionRepository $subs;
    private Vapid $vapid;
    /** @var array{private:string,public:string} */
    private array $ua;
    private string $auth;

    public function setUp(): void
    {
        parent::setUp();
        (new UserRepository($this->pdo))->create(['role_code' => 'user', 'username' => 'alice', 'email' => 'alice@example.org', 'first_name' => 'A', 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('x'), 'timezone' => 'Europe/Paris']);
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1, 'A')");
        $this->subs = new PushSubscriptionRepository($this->pdo);
        $k = Vapid::generate();
        $this->vapid = new Vapid($k['public'], $k['private'], 'mailto:admin@example.org');
        $this->ua = EcKeys::generate();
        $this->auth = random_bytes(16);
    }

    private function subscribe(string $endpoint, ?array $ua = null, ?string $auth = null): int
    {
        $ua ??= $this->ua;
        return $this->subs->upsert(1, $endpoint, B::encode($ua['public']), B::encode($auth ?? $this->auth), 'Test/1.0', null);
    }

    private function fake(array $script = []): object
    {
        return new class($script) implements PushHttpClient {
            /** @var list<array<string,mixed>> */
            public array $calls = [];
            /** @param array<string,array{status:int,error?:?string}|list<int>> $script */
            public function __construct(private array $script)
            {
            }

            public function post(string $url, array $headers, string $body, int $timeout, ?string $pinIp = null): array
            {
                $this->calls[] = compact('url', 'headers', 'body', 'timeout', 'pinIp');
                $s = $this->script[$url] ?? ['status' => 201];
                return ['status' => $s['status'], 'body' => '', 'error' => $s['error'] ?? null];
            }
        };
    }

    private function sender(object $http, bool $any = false): PushSender
    {
        return new PushSender($this->subs, $this->vapid, $http, new EndpointPolicy(['push.example'], $any, $any, fn () => ['93.184.216.34']));
    }

    private function delivery(string $title = 'Rappel : RV'): array
    {
        return ['id' => 42, 'user_id' => 1, 'title' => $title, 'body' => 'Le 18/11/2026 à 11:30'];
    }

    public function testSuccessfulDeliveryIsEncryptedAndAuthenticated(): void
    {
        $id = $this->subscribe('https://eu.push.example/send/abc');
        $http = $this->fake();
        $r = $this->sender($http)->send($this->delivery());
        $this->assertSame('ok', $r->kind);
        $c = $http->calls[0];
        $this->assertSame('https://eu.push.example/send/abc', $c['url']);
        $this->assertSame('93.184.216.34', $c['pinIp'], 'adresse validée épinglée');
        $h = $c['headers'];
        $this->assertSame('aes128gcm', $h['Content-Encoding']);
        $this->assertSame('3600', $h['TTL']);
        $this->assertTrue(preg_match('/^vapid t=[\w.-]+, k=' . preg_quote($this->vapid->publicKey(), '/') . '$/', $h['Authorization']) === 1);
        $plain = json_decode((string) WebPushEncryptor::decrypt($c['body'], $this->ua['private'], $this->ua['public'], $this->auth), true);
        $this->assertSame(['Rappel : RV', 'Le 18/11/2026 à 11:30', '/notifications', 'rvm-42'], [$plain['title'], $plain['body'], $plain['url'], $plain['tag']]);
        preg_match('/^vapid t=([\w.-]+), k=/', $h['Authorization'], $m);
        $claims = Vapid::verify($m[1], $this->vapid->publicKey());
        $this->assertSame('https://eu.push.example', $claims['aud']);
        $this->assertTrue($this->pdo->query("SELECT last_success_at FROM push_subscriptions WHERE id = $id")->fetchColumn() !== null);
    }

    public function testServiceResponsesAreMappedCorrectly(): void
    {
        $this->subscribe('https://a.push.example/gone');
        $s = $this->sender($this->fake(['https://a.push.example/gone' => ['status' => 410]]));
        $r = $s->send($this->delivery());
        $this->assertTrue($r->kind === 'fail' && str_contains($r->message, 'expiré'), '410 : abonnement supprimé et échec définitif (plus personne à notifier)');
        $this->assertSame(0, $this->subs->countFor(1));

        foreach ([[503, 'retry'], [429, 'retry'], [500, 'retry'], [0, 'retry'], [403, 'fail'], [400, 'fail'], [401, 'fail']] as [$code, $kind]) {
            $this->pdo->exec('DELETE FROM push_subscriptions');
            $this->subscribe('https://a.push.example/x');
            $r = $this->sender($this->fake(['https://a.push.example/x' => ['status' => $code, 'error' => $code === 0 ? 'timeout' : null]]))->send($this->delivery());
            $this->assertSame($kind, $r->kind, "HTTP $code");
            $this->assertSame($kind === 'fail' ? 1 : 0, (int) $this->pdo->query('SELECT failed_count FROM push_subscriptions')->fetchColumn(), "compteur d'échecs permanents pour $code");
        }
        $this->pdo->exec('DELETE FROM push_subscriptions');
        $this->assertSame('fail', $this->sender($this->fake())->send($this->delivery())->kind, 'aucun appareil');
    }

    public function testPartialSuccessCountsAsDeliveredAndPermanentFailuresRemoveSubscription(): void
    {
        $this->subscribe('https://a.push.example/ok');
        $this->subscribe('https://b.push.example/down', EcKeys::generate());
        $s = $this->sender($this->fake(['https://b.push.example/down' => ['status' => 503]]));
        $this->assertSame('ok', $s->send($this->delivery())->kind, 'un appareil servi suffit');
        $this->pdo->exec('DELETE FROM push_subscriptions');
        $this->subscribe('https://a.push.example/refuse');
        $refuse = $this->sender($this->fake(['https://a.push.example/refuse' => ['status' => 403]]));
        for ($i = 0; $i < 5; $i++) {
            $refuse->send($this->delivery());
        }
        $this->assertSame(0, $this->subs->countFor(1), 'après 5 refus consécutifs l\'abonnement est supprimé');
    }

    public function testForbiddenEndpointIsNeverCalled(): void
    {
        $this->subscribe('https://evil.example/steal');
        $this->subscribe('http://push.example/plain', EcKeys::generate());
        $http = $this->fake();
        $r = $this->sender($http)->send($this->delivery());
        $this->assertSame([0, 'fail'], [count($http->calls), $r->kind], 'SSRF : aucune requête sortante vers un hôte non reconnu ou en http');
    }

    public function testExpiredOrFailingSubscriptionsAreSkipped(): void
    {
        $id = $this->subscribe('https://a.push.example/x');
        $this->pdo->exec("UPDATE push_subscriptions SET expires_at = '2000-01-01 00:00:00' WHERE id = $id");
        $http = $this->fake();
        $this->assertSame('fail', $this->sender($http)->send($this->delivery())->kind);
        $this->assertSame([], $http->calls);
        $this->assertSame(1, $this->subs->purgeExpired());
    }

    public function testEngineDeliversThroughPushChannelWithRetries(): void
    {
        (new SettingsRepository($this->pdo))->saveNotifications(1, ['channels' => ['inapp', 'push'], 'privacy' => 'minimal']);
        $this->subscribe('https://a.push.example/flaky');
        $a = $this->pdo->exec("INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc, location) VALUES (UUID(), 1, 1, 'Entretien confidentiel', '2026-11-18 10:30:00', '2026-11-18 11:30:00', 'Cabinet')");
        $this->pdo->exec("INSERT INTO reminders (appointment_id, user_id, offset_minutes, channel) VALUES (1, 1, 15, 'push'), (1, 1, 15, 'inapp')");
        $http = $this->fake(['https://a.push.example/flaky' => ['status' => 503]]);
        $e = new NotificationEngine($this->pdo, 'w', [new InAppSender(), $this->sender($http)]);
        $t = new DateTimeImmutable('2026-11-18 10:16:00', new DateTimeZone('UTC'));
        $s = $e->run($t);
        $this->assertSame([2, 1, 1], [$s['planned'], $s['sent'], $s['retried']], 'in-app envoyé, push reporté');
        $this->assertSame(0, $e->run($t->modify('+30 seconds'))['claimed']);
        $http2 = $this->fake();
        $e2 = new NotificationEngine($this->pdo, 'w', [new InAppSender(), $this->sender($http2)]);
        $this->assertSame(1, $e2->run($t->modify('+2 minutes'))['sent'], 'service rétabli : remis');
        $plain = json_decode((string) WebPushEncryptor::decrypt($http2->calls[0]['body'], $this->ua['private'], $this->ua['public'], $this->auth), true);
        $this->assertSame('Rappel de rendez-vous', $plain['title']);
        $this->assertTrue(!str_contains(json_encode($plain), 'confidentiel') && !str_contains(json_encode($plain), 'Cabinet'), 'confidentialité « minimal » respectée jusque dans le push');
        $this->assertSame(['sent', 'sent'], array_column($this->pdo->query('SELECT status FROM notification_deliveries ORDER BY id')->fetchAll(), 'status'));
    }

    // ---- service HTTP réel simulé ---------------------------------------------------------------------------------------

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
        fclose($s);
        return $port;
    }

    public function testRealHttpRoundTripAgainstSimulatedPushService(): void
    {
        $port = self::freePort();
        $log = tempnam(sys_get_temp_dir(), 'push');
        $env = array_merge(getenv(), ['FAKE_PUSH_VAPID_PUB' => $this->vapid->publicKey(), 'FAKE_PUSH_UA_PRIV' => B::encode($this->ua['private']), 'FAKE_PUSH_UA_PUB' => B::encode($this->ua['public']),
            'FAKE_PUSH_AUTH' => B::encode($this->auth), 'FAKE_PUSH_LOG' => $log]);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", dirname(__DIR__) . '/Support/fake_push_service.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__, 2), $env);
        try {
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }
            $sender = new PushSender($this->subs, $this->vapid, new CurlPushHttpClient(), new EndpointPolicy([], true, true));
            $this->subscribe("http://127.0.0.1:$port/ok");
            $this->assertSame('ok', $sender->send(['id' => 7, 'user_id' => 1, 'title' => 'Rappel : é€🔔', 'body' => 'corps'])->kind);
            $l = json_decode(trim((string) file($log)[0]), true);
            $this->assertTrue($l['vapid_ok'] && $l['aud_ok'] && $l['exp_ok'], 'jeton VAPID valide : ' . json_encode($l));
            $this->assertSame(['aes128gcm', '3600', 'high', 'mailto:admin@example.org'], [$l['encoding'], $l['ttl'], $l['urgency'], $l['sub']]);
            $this->assertSame('Rappel : é€🔔', json_decode($l['plaintext'], true)['title'], 'charge utile déchiffrée par l\'abonné');
            $this->subs->deleteByEndpoint(1, "http://127.0.0.1:$port/ok");
            $this->subscribe("http://127.0.0.1:$port/gone");
            $this->assertSame('fail', $sender->send(['id' => 8, 'user_id' => 1, 'title' => 't', 'body' => 'b'])->kind);
            $this->assertSame(0, $this->subs->countFor(1), '410 réel : abonnement supprimé');
            $this->subscribe("http://127.0.0.1:$port/busy");
            $this->assertSame('retry', $sender->send(['id' => 9, 'user_id' => 1, 'title' => 't', 'body' => 'b'])->kind, '503 réel : nouvelle tentative');
            $this->subs->deleteByEndpoint(1, "http://127.0.0.1:$port/busy");
            $this->subscribe('http://127.0.0.1:1/injoignable');
            $this->assertSame('retry', $sender->send(['id' => 10, 'user_id' => 1, 'title' => 't', 'body' => 'b'])->kind, 'connexion refusée : nouvelle tentative');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($log);
        }
    }
}
