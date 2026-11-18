<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\EmailApiController;
use App\Core\Application;
use App\Core\Config;
use App\Core\Env;
use App\Core\Request;
use App\Core\Router;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\AuditLogger;
use App\Services\AuthService;
use App\Services\Mail\EmailSender;
use App\Services\Mail\SmtpMailer;
use App\Services\Notifications\Channels;
use App\Services\Notifications\InAppSender;
use App\Services\Notifications\NotificationEngine;
use DateTimeImmutable;
use DateTimeZone;
use Tests\DatabaseTestCase;

/** Canal e-mail : moteur de notifications → SMTP (faux serveur), réglages, e-mail de test. */
final class EmailDeliveryTest extends DatabaseTestCase
{
    private $proc = null;
    private string $out = '';

    public function setUp(): void
    {
        parent::setUp();
        (new UserRepository($this->pdo))->create(['role_code' => 'user', 'username' => 'alice', 'email' => 'alice@example.org', 'first_name' => 'A', 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('Correct-Horse-9'), 'timezone' => 'Europe/Paris']);
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1, 'A')");
    }

    public function tearDown(): void
    {
        if ($this->proc) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
        @unlink($this->out);
        foreach (['MAIL_ENABLED' => 'false', 'MAIL_HOST' => '', 'MAIL_FROM' => '', 'MAIL_ENCRYPTION' => 'tls', 'MAIL_PORT' => '587', 'MAIL_USER' => '', 'MAIL_PASSWORD' => ''] as $k => $v) {
            Env::set($k, $v);
        }
        Config::init(dirname(__DIR__, 2) . '/config');
    }

    private function smtp(array $env = []): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $n = (string) stream_socket_get_name($s, false);
        $port = (int) substr($n, strrpos($n, ':') + 1);
        fclose($s);
        $this->out = tempnam(sys_get_temp_dir(), 'smtp');
        $this->proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/fake_smtp.php', (string) $port, $this->out], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p, null, array_merge(getenv(), $env));
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
        foreach (['MAIL_ENABLED' => 'true', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => (string) $port, 'MAIL_ENCRYPTION' => 'none', 'MAIL_FROM' => 'noreply@example.org', 'MAIL_USER' => '', 'MAIL_PASSWORD' => ''] as $k => $v) {
            Env::set($k, $v);
        }
        Config::init(dirname(__DIR__, 2) . '/config');
        return $port;
    }

    /** @return list<array<string,mixed>> */
    private function mails(): array
    {
        return array_map(fn ($l) => json_decode($l, true), array_filter(array_map('trim', file($this->out) ?: [])));
    }

    private function remindByEmail(string $title = 'Réunion confidentielle'): void
    {
        (new SettingsRepository($this->pdo))->saveNotifications(1, ['channels' => ['inapp', 'email']]);
        $this->pdo->exec("INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc, location) VALUES (UUID(), 1, 1, '$title', '2026-11-18 10:30:00', '2026-11-18 11:30:00', 'Salle 4')");
        $this->pdo->exec("INSERT INTO reminders (appointment_id, user_id, offset_minutes, channel) VALUES (1, 1, 15, 'email')");
    }

    private function engine(): NotificationEngine
    {
        return new NotificationEngine($this->pdo, 'w', array_merge([new InAppSender()], array_slice(Channels::senders($this->pdo, true), 0)));
    }

    public function testReminderIsEmailedByTheServer(): void
    {
        $this->smtp();
        $this->assertSame(['inapp', 'email'], Channels::available());
        $this->remindByEmail();
        $s = $this->engine()->run(new DateTimeImmutable('2026-11-18 10:16:00', new DateTimeZone('UTC')));
        $this->assertSame([1, 1], [$s['planned'], $s['sent']]);
        $m = $this->mails()[0];
        $this->assertTrue(str_contains($m['to'][0], '<alice@example.org>'));
        [$head, $body] = explode("\r\n\r\n", $m['data'], 2);
        preg_match('/^Subject: =\?UTF-8\?B\?(.+)\?=\r?$/m', $head, $sm);
        $this->assertSame('Rappel : Réunion confidentielle', base64_decode($sm[1]));
        $text = quoted_printable_decode($body);
        $this->assertTrue(str_contains($text, 'Salle 4') && str_contains($text, '11:30'), $text);
        $this->assertSame('sent', $this->pdo->query("SELECT status FROM notification_deliveries WHERE channel = 'email'")->fetchColumn());
        $this->assertSame(0, $this->engine()->run(new DateTimeImmutable('2026-11-18 10:17:00', new DateTimeZone('UTC')))['sent'], 'un seul e-mail');
        $this->assertSame(1, count($this->mails()));
    }

    public function testSmtpFailuresAreRetriedOrAbandoned(): void
    {
        $this->smtp(['FAKE_SMTP_RCPT' => '451']);
        $this->remindByEmail();
        $t = new DateTimeImmutable('2026-11-18 10:16:00', new DateTimeZone('UTC'));
        $s = $this->engine()->run($t);
        $this->assertSame(1, $s['retried']);
        $d = $this->pdo->query("SELECT status, attempts, last_error FROM notification_deliveries WHERE channel = 'email'")->fetch();
        $this->assertSame(['pending', 1], [$d['status'], (int) $d['attempts']]);
        $this->assertTrue(str_contains($d['last_error'], '451'));
        $this->tearDown();
        $this->setUp();
        $this->smtp(['FAKE_SMTP_RCPT' => '550']);
        $this->remindByEmail();
        $this->assertSame(1, $this->engine()->run($t)['failed'], '5xx : échec définitif sans nouvelle tentative');
    }

    public function testQuietHoursDelayEmailButNotInApp(): void
    {
        $this->smtp();
        $this->remindByEmail();
        $this->pdo->exec("INSERT INTO reminders (appointment_id, user_id, offset_minutes, channel) VALUES (1, 1, 15, 'inapp')");
        (new SettingsRepository($this->pdo))->saveNotifications(1, ['quiet_start' => '11:00', 'quiet_end' => '12:00']); // 11:16 à Paris
        $s = $this->engine()->run(new DateTimeImmutable('2026-11-18 10:16:00', new DateTimeZone('UTC')));
        $this->assertSame([1, 1], [$s['sent'], $s['deferred']]);
        $this->assertSame([], $this->mails(), 'rien envoyé pendant les heures silencieuses');
    }

    public function testTestEmailEndpoint(): void
    {
        $users = new UserRepository($this->pdo);
        $auth = new AuthService($this->pdo, $users, new AuditLogger($this->pdo));
        $router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($router, $this->pdo);
        $login = $auth->login('alice', 'Correct-Horse-9', '203.0.113.1', 't');
        $call = function () use ($router, $login) {
            $res = Application::handle(new Request('POST', '/api/v1/email/test', [], '', ['cookie' => 'rvm_session=' . $login['token'], 'x-csrf-token' => $login['csrf']], '203.0.113.1'), $router);
            return ['status' => $res->status, 'json' => json_decode($res->body, true)];
        };
        $this->assertSame(503, $call()['status'], 'SMTP non configuré');
        $this->smtp();
        $r = $call();
        $this->assertSame([200, 'a***@example.org'], [$r['status'], $r['json']['to']], 'adresse masquée dans la réponse');
        $this->assertSame(1, count($this->mails()));
        $call();
        $call();
        $this->assertSame(429, $call()['status'], '3 tests par minute au maximum');
        $res = Application::handle(new Request('POST', '/api/v1/email/test', [], '', ['cookie' => 'rvm_session=' . $login['token']]), $router);
        $this->assertSame(403, $res->status, 'CSRF requis');
    }

    public function testSmtpFailureIsReportedWithoutLeakingSecrets(): void
    {
        $this->smtp(['FAKE_SMTP_RCPT' => '550']);
        Env::set('MAIL_PASSWORD', 'TopSecret!');
        Config::init(dirname(__DIR__, 2) . '/config');
        $auth = new AuthService($this->pdo, new UserRepository($this->pdo), new AuditLogger($this->pdo));
        $router = new Router();
        (require dirname(__DIR__, 2) . '/api/routes.php')($router, $this->pdo);
        $login = $auth->login('alice', 'Correct-Horse-9', '203.0.113.1', 't');
        $res = Application::handle(new Request('POST', '/api/v1/email/test', [], '', ['cookie' => 'rvm_session=' . $login['token'], 'x-csrf-token' => $login['csrf']], '203.0.113.1'), $router);
        $this->assertSame(502, $res->status);
        $this->assertTrue(!str_contains($res->body, 'TopSecret!'));
    }
}
