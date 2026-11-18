<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Env;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Security\PasswordHasher;
use App\Services\Notifications\ChannelSender;
use App\Services\Notifications\InAppSender;
use App\Services\Notifications\NotificationEngine;
use App\Services\Notifications\SendResult;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\DatabaseTestCase;

/** Moteur de notifications : planification, réservation atomique, tentatives, annulation, heures silencieuses, séries. */
final class NotificationEngineTest extends DatabaseTestCase
{
    private SettingsRepository $settings;

    public function setUp(): void
    {
        parent::setUp();
        $users = new UserRepository($this->pdo);
        foreach (['alice', 'bob'] as $n) {
            $users->create(['role_code' => 'user', 'username' => $n, 'email' => "$n@example.org", 'first_name' => $n, 'last_name' => 'T', 'password_hash' => PasswordHasher::hash('x'), 'timezone' => 'Europe/Paris']);
        }
        $this->pdo->exec("INSERT INTO calendars (owner_id, name) VALUES (1,'A'), (2,'B')");
        $this->settings = new SettingsRepository($this->pdo);
    }

    private function now(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    private function engine(string $id = 'w1', array $senders = [], ?PDO $pdo = null): NotificationEngine
    {
        return new NotificationEngine($pdo ?? $this->pdo, $id, $senders);
    }

    /** @return int identifiant du rendez-vous */
    private function appt(string $start, string $end, array $o = []): int
    {
        $this->pdo->prepare('INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc, all_day, status, location, deleted_at) VALUES (UUID(), ?, 1, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$o['cal'] ?? 1, $o['title'] ?? 'Réunion', $start, $end, $o['all_day'] ?? 0, $o['status'] ?? 'scheduled', $o['location'] ?? null, $o['deleted'] ?? null]);
        return (int) $this->pdo->lastInsertId();
    }

    private function remind(int $appt, int $minutes, int $user = 1, string $channel = 'inapp'): int
    {
        $this->pdo->prepare('INSERT INTO reminders (appointment_id, user_id, offset_minutes, channel) VALUES (?, ?, ?, ?)')->execute([$appt, $user, $minutes, $channel]);
        return (int) $this->pdo->lastInsertId();
    }

    private function deliveries(string $where = '1'): array
    {
        return $this->pdo->query("SELECT * FROM notification_deliveries WHERE $where ORDER BY id")->fetchAll();
    }

    public function testDueReminderIsPlannedOnceAndDeliveredInApp(): void
    {
        $a = $this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00', ['location' => 'Salle A']);
        $this->remind($a, 15);
        $e = $this->engine();
        $this->assertSame(0, $e->run($this->now('2026-11-18 10:10:00'))['planned'], 'pas encore l\'heure');
        $s = $e->run($this->now('2026-11-18 10:16:00'));
        $this->assertSame(['planned' => 1, 'claimed' => 1, 'sent' => 1], array_intersect_key($s, array_flip(['planned', 'claimed', 'sent'])));
        $d = $this->deliveries()[0];
        $this->assertSame('sent', $d['status']);
        $this->assertSame('Rappel : Réunion', $d['title']);
        $this->assertTrue(str_contains($d['body'], '11:30') && str_contains($d['body'], 'Salle A') && str_contains($d['body'], '15 min'), $d['body']);
        $this->assertSame('2026-11-18 10:15:00', $d['scheduled_at']);
        $again = $e->run($this->now('2026-11-18 10:17:00'));
        $this->assertSame(0, $again['planned'] + $again['claimed'] + $again['sent'], 'idempotent : aucun doublon');
        $this->assertSame(1, count($this->deliveries()));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sync_changes WHERE entity_type = 'notification' AND changed_by = 1")->fetchColumn(), 'le destinataire est informé par la synchronisation');
    }

    public function testConditionsThatSuppressReminders(): void
    {
        $now = $this->now('2026-11-18 10:16:00');
        $ok = fn (array $o = []) => $this->remind($this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00', $o), 15);
        $ok(['status' => 'cancelled']);
        $ok(['status' => 'completed']);
        $ok(['deleted' => '2026-11-01 00:00:00']);
        $this->remind($this->appt('2026-11-18 08:00:00', '2026-11-18 09:00:00'), 15, 1); // déjà terminé
        $this->remind($this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00', ['cal' => 2]), 15, 1); // calendrier de Bob, non partagé
        $this->remind($this->appt('2026-11-19 10:30:00', '2026-11-19 11:30:00'), 15, 1); // demain
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = 2');
        $this->remind($this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00', ['cal' => 2]), 15, 2); // compte désactivé
        $this->assertSame(0, $this->engine()->run($now)['planned']);
        // l'utilisateur désactive ses notifications
        $this->remind($this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00', ['title' => 'Valide']), 15, 1);
        $this->settings->saveNotifications(1, ['enabled' => false]);
        $this->assertSame(0, $this->engine()->run($now)['planned']);
        $this->settings->saveNotifications(1, ['enabled' => true]);
        $this->assertSame(1, $this->engine()->run($now)['sent']);
    }

    public function testShareRevocationStopsReminders(): void
    {
        $a = $this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00', ['cal' => 2]);
        $this->remind($a, 15, 1);
        $this->pdo->exec('INSERT INTO calendar_permissions (calendar_id, user_id, can_read) VALUES (2, 1, 1)');
        $this->pdo->exec('DELETE FROM calendar_permissions');
        $this->assertSame(0, $this->engine()->run($this->now('2026-11-18 10:16:00'))['planned']);
    }

    public function testMissedRemindersAreCaughtUpOrSkippedAccordingToSettings(): void
    {
        $a = $this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00');
        $this->remind($a, 60); // déclenché à 09:30, le worker était arrêté
        $this->settings->saveNotifications(1, ['missed' => 'skip']);
        $this->assertSame(0, $this->engine()->run($this->now('2026-11-18 10:16:00'))['planned'], 'skip : rien n\'est rattrapé après 5 minutes');
        $this->settings->saveNotifications(1, ['missed' => 'deliver']);
        $this->assertSame(1, $this->engine()->run($this->now('2026-11-18 10:16:00'))['sent'], 'deliver : rattrapage');
        $b = $this->appt('2026-11-19 12:00:00', '2026-11-19 13:00:00');
        $this->remind($b, 5);
        $this->assertSame(0, $this->engine()->run($this->now('2026-11-20 12:00:00'))['planned'], 'rendez-vous passé : rien');
    }

    public function testPrivacyMinimalHidesDetails(): void
    {
        $this->settings->saveNotifications(1, ['privacy' => 'minimal']);
        $a = $this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00', ['title' => 'Entretien confidentiel', 'location' => 'Cabinet']);
        $this->remind($a, 10);
        $this->engine()->run($this->now('2026-11-18 10:21:00'));
        $d = $this->deliveries()[0];
        $this->assertTrue(!str_contains($d['title'] . $d['body'], 'confidentiel') && !str_contains($d['body'], 'Cabinet'));
        $this->assertSame('Rappel de rendez-vous', $d['title']);
    }

    public function testNotificationsCancelledWhenAppointmentChangesAfterPlanning(): void
    {
        $now = $this->now('2026-11-18 10:16:00');
        $ids = [];
        foreach ([1, 2, 3, 4] as $i) {
            $ids[$i] = $this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00', ['title' => "R$i"]);
            $this->remind($ids[$i], 15);
        }
        $e = $this->engine();
        $this->assertSame(4, $e->planner()->plan($now));
        $this->pdo->exec("UPDATE appointments SET status = 'cancelled' WHERE id = {$ids[1]}");
        $this->pdo->exec("UPDATE appointments SET deleted_at = NOW() WHERE id = {$ids[2]}");
        $this->pdo->exec("UPDATE appointments SET start_utc = '2026-11-18 12:00:00', end_utc = '2026-11-18 13:00:00' WHERE id = {$ids[3]}");
        $s = $e->run($now);
        $this->assertSame(3, $s['cancelled']);
        $this->assertSame(1, $s['sent']);
        $this->assertSame(['cancelled', 'cancelled', 'cancelled', 'sent'], array_column($this->deliveries(), 'status'));
        // le rendez-vous déplacé reçoit un nouveau rappel à sa nouvelle heure
        $this->assertSame(1, $e->run($this->now('2026-11-18 11:46:00'))['sent']);
    }

    // ---- tentatives ----------------------------------------------------------------------------------------------

    private function pushSender(array $script): ChannelSender
    {
        return new class($script) implements ChannelSender {
            public int $calls = 0;
            /** @param list<SendResult> $script */
            public function __construct(private array $script)
            {
            }

            public function channel(): string
            {
                return 'push';
            }

            public function send(array $delivery): SendResult
            {
                return $this->script[min($this->calls++, count($this->script) - 1)];
            }
        };
    }

    private function pushSetup(): void
    {
        $this->settings->saveNotifications(1, ['channels' => ['inapp', 'push']]);
        $this->remind($this->appt('2026-11-18 10:30:00', '2026-11-19 20:00:00'), 15, 1, 'push');
    }

    public function testRetriesWithBackoffThenFailure(): void
    {
        $this->pushSetup();
        $sender = $this->pushSender([SendResult::retry('serveur indisponible')]);
        $e = $this->engine('w', [new InAppSender(), $sender]);
        $t = $this->now('2026-11-18 10:16:00');
        $this->assertSame(1, $e->run($t)['retried']);
        $d = $this->deliveries()[0];
        $this->assertSame(['pending', 1, '2026-11-18 10:17:00', 'serveur indisponible'], [$d['status'], (int) $d['attempts'], $d['next_attempt_at'], $d['last_error']]);
        $this->assertSame(0, $e->run($t->modify('+30 seconds'))['claimed'], 'pas avant le repli');
        $this->assertSame(1, $e->run($t->modify('+61 seconds'))['retried']);
        $this->assertSame(2, (int) $this->deliveries()[0]['attempts']);
        $e->run($t->modify('+10 minutes')); $e->run($t->modify('+2 hours')); // 3e et 4e tentatives
        $s = $e->run($t->modify('+12 hours'));
        $this->assertSame(1, $s['failed'], 'après 5 tentatives : échec définitif');
        $d = $this->deliveries()[0];
        $this->assertSame(['failed', 5], [$d['status'], (int) $d['attempts']]);
        $this->assertSame(5, $sender->calls);
        $this->assertSame(0, $e->run($t->modify('+2 days'))['claimed'], 'plus jamais réessayé');
    }

    public function testRecoveryAfterTemporaryFailureAndPermanentFailure(): void
    {
        $this->pushSetup();
        $ok = $this->pushSender([SendResult::retry('oups'), SendResult::ok()]);
        $e = $this->engine('w', [new InAppSender(), $ok]);
        $t = $this->now('2026-11-18 10:16:00');
        $e->run($t);
        $this->assertSame(1, $e->run($t->modify('+2 minutes'))['sent']);
        $this->assertSame('sent', $this->deliveries()[0]['status']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM sync_changes WHERE entity_type = 'notification'")->fetchColumn(), 'seul le canal « dans l\'application » alimente la synchronisation');

        $this->pdo->exec('DELETE FROM notification_deliveries');
        $dead = $this->pushSender([SendResult::fail('abonnement expiré')]);
        $e2 = $this->engine('w2', [new InAppSender(), $dead]);
        $this->assertSame(1, $e2->run($t->modify('+3 minutes'))['failed']);
        $d = $this->deliveries()[0];
        $this->assertSame(['failed', 1, 'abonnement expiré'], [$d['status'], (int) $d['attempts'], $d['last_error']], 'échec définitif immédiat');
    }

    public function testSenderExceptionIsTreatedAsRetry(): void
    {
        $this->pushSetup();
        $boom = new class implements ChannelSender {
            public function channel(): string
            {
                return 'push';
            }

            public function send(array $delivery): SendResult
            {
                throw new \RuntimeException('réseau coupé');
            }
        };
        $this->assertSame(1, $this->engine('w', [new InAppSender(), $boom])->run($this->now('2026-11-18 10:16:00'))['retried']);
        $this->assertTrue(str_contains($this->deliveries()[0]['last_error'], 'réseau coupé'));
    }

    public function testChannelWithoutSenderIsLeftPending(): void
    {
        $this->pushSetup();
        $s = $this->engine()->run($this->now('2026-11-18 10:16:00'));
        $this->assertSame(0, $s['claimed'], 'aucun expéditeur push : la ligne attend la phase 13 sans être perdue');
        $this->assertSame('pending', $this->deliveries()[0]['status']);
    }

    public function testQuietHoursDeferPushButNotInApp(): void
    {
        $this->settings->saveNotifications(1, ['channels' => ['inapp', 'push'], 'quiet_start' => '22:00', 'quiet_end' => '07:00']);
        $a = $this->appt('2026-11-18 23:00:00', '2026-11-19 00:00:00'); // 00:00 à Paris
        $this->remind($a, 30, 1, 'push');
        $this->remind($a, 30, 1, 'inapp');
        $sender = $this->pushSender([SendResult::ok()]);
        $e = $this->engine('w', [new InAppSender(), $sender]);
        $s = $e->run($this->now('2026-11-18 22:35:00')); // 23:35 à Paris : silence
        $this->assertSame([1, 1], [$s['sent'], $s['deferred']]);
        $this->assertSame(0, $sender->calls);
        $d = $this->deliveries("channel = 'push'")[0];
        $this->assertSame(['pending', 0, '2026-11-19 06:00:00'], [$d['status'], (int) $d['attempts'], $d['next_attempt_at']], 'reporté à 07:00 Paris, tentative non comptée');
        $this->assertSame(0, $e->run($this->now('2026-11-19 05:59:00'))['claimed']);
        $this->assertSame(0, $e->run($this->now('2026-11-19 06:01:00'))['sent'], 'le rendez-vous est terminé à 06:01 : annulé, pas envoyé');
    }

    // ---- concurrence et reprise ----------------------------------------------------------------------------------------

    public function testStaleReservationsAreRecovered(): void
    {
        $a = $this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00');
        $r = $this->remind($a, 15);
        $ins = $this->pdo->prepare("INSERT INTO notification_deliveries (reminder_id, appointment_id, user_id, channel, occurrence_start_utc, scheduled_at, status, attempts, locked_by, locked_at, title)
            VALUES (?, ?, 1, 'inapp', ?, '2026-11-18 10:15:00', 'processing', ?, 'mort', '2026-11-18 10:00:00', 't')");
        $ins->execute([$r, $a, '2026-11-18 10:30:00', 1]);
        $r2 = $this->remind($a, 20);
        $ins->execute([$r2, $a, '2026-11-18 10:30:00', 5]);
        $e = $this->engine();
        $this->assertSame(0, $e->recoverStale($this->now('2026-11-18 10:02:00')), 'réservation récente : intacte');
        $this->assertSame(2, $e->recoverStale($this->now('2026-11-18 10:16:00')));
        $this->assertSame(['pending', 'failed'], array_column($this->deliveries(), 'status'), 'tentatives épuisées : échec');
        $this->assertSame(1, $e->run($this->now('2026-11-18 10:16:00'))['sent'], 'la ligne reprise est envoyée');
    }

    private function otherConnection(): PDO
    {
        return Database::connection(['driver' => 'mysql', 'host' => Env::get('TEST_DB_HOST', '127.0.0.1'), 'port' => Env::int('TEST_DB_PORT', 3306), 'name' => Env::get('TEST_DB_NAME'),
            'user' => Env::get('TEST_DB_USER'), 'password' => Env::get('TEST_DB_PASSWORD'), 'ssl_mode' => 'disabled', 'ssl_ca' => '']);
    }

    public function testClaimsAreDisjointBetweenWorkers(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $a = $this->appt('2026-11-18 10:30:00', '2026-11-18 11:30:00', ['title' => "R$i"]);
            $this->remind($a, 15);
        }
        $now = $this->now('2026-11-18 10:16:00');
        $this->engine()->planner()->plan($now);
        $w1 = $this->engine('w1');
        $w2 = $this->engine('w2', [], $this->otherConnection());
        $a = $w1->claim($now, 4);
        $b = $w2->claim($now, 4);
        $c = $w1->claim($now, 4);
        $ids = fn ($rows) => array_map(fn ($r) => (int) $r['id'], $rows);
        $all = array_merge($ids($a), $ids($b), $ids($c));
        $this->assertSame([4, 4, 2], [count($a), count($b), count($c)]);
        $this->assertSame(10, count(array_unique($all)), 'aucune ligne réservée deux fois');
        $owners = array_unique(array_map(fn ($b) => explode('#', $b)[0], array_column($this->deliveries("status = 'processing'"), 'locked_by')));
        sort($owners);
        $this->assertSame(['w1', 'w2'], $owners, 'chaque ligne est réservée par un seul worker');
    }

    public function testParallelWorkerProcessesDeliverEachNotificationExactlyOnce(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $now->modify('+5 minutes')->format('Y-m-d H:i:s');
        $end = $now->modify('+65 minutes')->format('Y-m-d H:i:s');
        $a = $this->pdo->prepare('INSERT INTO appointments (uid, calendar_id, created_by, title, start_utc, end_utc) VALUES (UUID(), 1, 1, ?, ?, ?)');
        $r = $this->pdo->prepare("INSERT INTO reminders (appointment_id, user_id, offset_minutes, channel) VALUES (?, 1, 10, 'inapp')");
        $this->pdo->beginTransaction();
        for ($i = 0; $i < 300; $i++) {
            $a->execute(["R$i", $start, $end]);
            $r->execute([$this->pdo->lastInsertId()]);
        }
        $this->pdo->commit();
        $this->engine()->planner()->plan($now->modify('+1 minute')); // 300 lignes « pending » à se partager
        $this->assertSame(300, (int) $this->pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'pending'")->fetchColumn());
        @unlink(dirname(__DIR__, 2) . '/storage/logs/cron_heartbeat');
        $env = array_merge(getenv(), ['DB_HOST' => Env::get('TEST_DB_HOST', '127.0.0.1'), 'DB_PORT' => (string) Env::int('TEST_DB_PORT', 3306), 'DB_NAME' => Env::get('TEST_DB_NAME'),
            'DB_USER' => Env::get('TEST_DB_USER'), 'DB_PASSWORD' => Env::get('TEST_DB_PASSWORD'), 'DB_SSL_MODE' => 'disabled', 'APP_ENV' => 'testing']);
        $procs = [];
        for ($i = 0; $i < 4; $i++) {
            $procs[] = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/workers/reminder_worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
            $procs[$i] = [$procs[$i], $pipes];
        }
        foreach ($procs as [$p, $pipes]) {
            stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            $this->assertSame(0, proc_close($p), 'worker en échec : ' . $err);
        }
        $this->assertSame(300, (int) $this->pdo->query('SELECT COUNT(*) FROM notification_deliveries')->fetchColumn(), 'une seule notification par rappel malgré 4 workers');
        $this->assertSame(300, (int) $this->pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'sent' AND attempts = 1")->fetchColumn(), 'chacune envoyée une seule fois');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status IN ('pending', 'processing')")->fetchColumn());
        $this->assertSame(300, (int) $this->pdo->query("SELECT COUNT(*) FROM sync_changes WHERE entity_type = 'notification'")->fetchColumn());
        $this->assertTrue(is_file(dirname(__DIR__, 2) . '/storage/logs/cron_heartbeat') && filemtime(dirname(__DIR__, 2) . '/storage/logs/cron_heartbeat') > time() - 60, 'le worker signale son passage (vérifié par l\'installateur)');
    }

    // ---- séries, journées entières, aperçu ----------------------------------------------------------------------------------

    private function weekly(int $offset = 30, string $rule = 'FREQ=WEEKLY'): int
    {
        $a = $this->appt('2026-11-18 08:00:00', '2026-11-18 09:00:00', ['title' => 'Hebdo']); // mercredi 09:00 Paris
        $this->pdo->prepare("INSERT INTO appointment_recurrence (appointment_id, rrule, dtstart_local, timezone) VALUES (?, ?, '2026-11-18 09:00:00', 'Europe/Paris')")->execute([$a, $rule]);
        $this->remind($a, $offset);
        return $a;
    }

    public function testSeriesRemindEachOccurrenceOnceAndRespectExceptions(): void
    {
        $a = $this->weekly(30);
        $e = $this->engine();
        $this->assertSame(1, $e->run($this->now('2026-11-18 07:35:00'))['sent'], '1re occurrence');
        $this->assertSame(0, $e->run($this->now('2026-11-19 12:00:00'))['planned']);
        $this->assertSame(1, $e->run($this->now('2026-11-25 07:35:00'))['sent'], '2e occurrence');
        $this->assertSame(['2026-11-18 08:00:00', '2026-11-25 08:00:00'], array_column($this->deliveries(), 'occurrence_start_utc'));
        // 3e occurrence annulée, 4e déplacée d'une heure
        $this->pdo->exec("INSERT INTO appointment_exceptions (appointment_id, original_start_utc, exception_type) VALUES ($a, '2026-12-02 08:00:00', 'cancelled')");
        $this->pdo->exec("INSERT INTO appointment_exceptions (appointment_id, original_start_utc, exception_type, new_start_utc, new_end_utc) VALUES ($a, '2026-12-09 08:00:00', 'modified', '2026-12-09 09:00:00', '2026-12-09 10:00:00')");
        $this->assertSame(0, $e->run($this->now('2026-12-02 07:35:00'))['planned'], 'occurrence annulée : aucun rappel');
        $this->assertSame(0, $e->run($this->now('2026-12-09 07:35:00'))['planned'], 'occurrence déplacée : pas à l\'heure d\'origine');
        $s = $e->run($this->now('2026-12-09 08:35:00'));
        $this->assertSame(1, $s['sent'], 'à la nouvelle heure');
        $all = $this->deliveries();
        $last = array_pop($all);
        $this->assertSame('2026-12-09 08:00:00', $last['occurrence_start_utc'], 'clé = début d\'origine');
        $this->assertSame('2026-12-09 08:30:00', $last['scheduled_at']);
    }

    public function testSeriesNotificationCancelledIfOccurrenceCancelledAfterPlanning(): void
    {
        $a = $this->weekly(30);
        $e = $this->engine();
        $now = $this->now('2026-11-18 07:35:00');
        $this->assertSame(1, $e->planner()->plan($now));
        $this->pdo->exec("INSERT INTO appointment_exceptions (appointment_id, original_start_utc, exception_type) VALUES ($a, '2026-11-18 08:00:00', 'cancelled')");
        $this->assertSame(1, $e->run($now)['cancelled']);
    }

    public function testAllDayReminderFiresAtLocalMidnight(): void
    {
        $a = $this->appt('2026-11-20 00:00:00', '2026-11-21 00:00:00', ['all_day' => 1, 'title' => 'Férié']);
        $this->remind($a, 0);
        $e = $this->engine();
        $this->assertSame(0, $e->run($this->now('2026-11-19 22:30:00'))['planned'], 'avant minuit à Paris');
        $this->assertSame(1, $e->run($this->now('2026-11-19 23:05:00'))['sent'], '00:05 le 20/11 à Paris');
        $d = $this->deliveries()[0];
        $this->assertSame('2026-11-19 23:00:00', $d['scheduled_at']);
        $this->assertTrue(str_contains($d['body'], 'journée entière'));
    }

    public function testUpcomingPreviewIsComputedNotStored(): void
    {
        $this->remind($this->appt('2026-11-19 10:00:00', '2026-11-19 11:00:00', ['title' => 'Demain']), 60);
        $this->remind($this->appt('2026-12-30 10:00:00', '2026-12-30 11:00:00', ['title' => 'Trop loin']), 60);
        $this->weekly(30);
        $up = $this->engine()->planner()->upcoming(1, $this->now('2026-11-18 12:00:00'), 7);
        $this->assertSame(['2026-11-19 09:00:00', '2026-11-25 07:30:00'], array_column($up, 'trigger_at'));
        $this->assertSame('Rappel : Demain', $up[0]['title']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notification_deliveries')->fetchColumn(), 'rien n\'est stocké');
        $this->settings->saveNotifications(1, ['enabled' => false]);
        $this->assertSame([], $this->engine()->planner()->upcoming(1, $this->now('2026-11-18 12:00:00'), 7));
    }
}
