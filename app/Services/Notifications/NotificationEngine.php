<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Config;
use App\Repositories\AppointmentRepository;
use App\Repositories\SettingsRepository;
use App\Services\Recurrence\RecurrenceService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Moteur de notifications côté serveur (exécuté par cron via workers/reminder_worker.php, indépendamment de tout navigateur) :
 *  1. reprise des réservations abandonnées (worker arrêté) ;
 *  2. planification des rappels dus (idempotente) ;
 *  3. réservation atomique par lots (UPDATE … LIMIT) : deux workers ne reçoivent jamais la même ligne ;
 *  4. validation à l'envoi (rendez-vous supprimé, annulé, terminé ou déplacé → notification annulée) ;
 *  5. envoi par canal, nouvelles tentatives avec repli, journalisation du résultat.
 */
final class NotificationEngine
{
    private ReminderPlanner $planner;
    private SettingsRepository $settings;
    /** @var array<string,ChannelSender> */
    private array $senders = [];
    private int $claimSeq = 0;

    /** @param list<ChannelSender> $senders */
    public function __construct(private PDO $pdo, private string $workerId, array $senders = [])
    {
        $this->settings = new SettingsRepository($pdo);
        $this->planner = new ReminderPlanner($pdo, $this->settings);
        foreach ($senders ?: [new InAppSender()] as $s) {
            $this->senders[$s->channel()] = $s;
        }
    }

    public function planner(): ReminderPlanner
    {
        return $this->planner;
    }

    /** @return array<string,int> compteurs de l'exécution */
    public function run(?DateTimeImmutable $now = null, int $batch = 100, int $maxBatches = 20): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stats = ['recovered' => 0, 'planned' => 0, 'claimed' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0, 'cancelled' => 0, 'deferred' => 0];
        $stats['recovered'] = $this->recoverStale($now);
        $stats['planned'] = $this->planOnce($now);
        for ($i = 0; $i < $maxBatches; $i++) {
            $rows = $this->retry(fn () => $this->claim($now, $batch));
            if (!$rows) {
                break;
            }
            $stats['claimed'] += count($rows);
            foreach ($rows as $d) {
                $stats[$this->process($d, $now)]++;
            }
        }
        return $stats;
    }

    /** Un seul worker planifie à la fois (verrou nommé) ; les autres passent directement à la réservation. */
    private function planOnce(DateTimeImmutable $now): int
    {
        $got = (int) $this->pdo->query("SELECT GET_LOCK('rvm_reminder_planner', 0)")->fetchColumn();
        if ($got !== 1) {
            return 0;
        }
        try {
            return $this->retry(fn () => $this->planner->plan($now));
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('rvm_reminder_planner')")->fetchAll();
        }
    }

    /** @param list<mixed> $args Écriture avec reprise sur interblocage. */
    private function write(string $sql, array $args): int
    {
        return $this->retry(function () use ($sql, $args): int {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            return $st->rowCount();
        });
    }

    /** Interblocage InnoDB (1213) ou délai d'attente de verrou (1205) : l'instruction n'a rien modifié, on réessaie. */
    private function retry(callable $fn, int $tries = 6): mixed
    {
        for ($i = 0;; $i++) {
            try {
                return $fn();
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (($code === 1213 || $code === 1205) && $i < $tries - 1) {
                    usleep(random_int(20000, 150000));
                    continue;
                }
                throw $e;
            }
        }
    }

    /** Remet à « pending » (ou « failed » si les tentatives sont épuisées) les lignes réservées depuis trop longtemps. */
    public function recoverStale(DateTimeImmutable $now): int
    {
        $max = (int) Config::get('notifications.max_attempts', 5);
        $cut = $now->modify('-' . (int) Config::get('notifications.lock_timeout', 300) . ' seconds')->format('Y-m-d H:i:s');
        return $this->write("UPDATE notification_deliveries SET status = IF(attempts >= ?, 'failed', 'pending'), locked_by = NULL, locked_at = NULL,
            last_error = 'Interrompu : worker arrêté pendant l\\'envoi' WHERE status = 'processing' AND locked_at < ?", [$max, $cut]);
    }

    /**
     * Réservation atomique : l'UPDATE ne touche que des lignes encore « pending » ; deux workers concurrents se partagent
     * donc les lignes sans jamais en recevoir une en double.
     * @return list<array<string,mixed>>
     */
    public function claim(DateTimeImmutable $now, int $limit): array
    {
        $channels = array_keys($this->senders);
        $in = implode(',', array_fill(0, count($channels), '?'));
        $n = $now->format('Y-m-d H:i:s');
        $st = $this->pdo->prepare("UPDATE notification_deliveries SET status = 'processing', locked_by = ?, locked_at = ?, attempts = attempts + 1
            WHERE status = 'pending' AND scheduled_at <= ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?) AND channel IN ($in) ORDER BY scheduled_at, id LIMIT " . max(1, $limit));
        $token = substr($this->workerId, 0, 50) . '#' . ++$this->claimSeq; // distingue les lots successifs d'un même worker
        $st->execute(array_merge([$token, $n, $n, $n], $channels));
        if ($st->rowCount() === 0) {
            return [];
        }
        $q = $this->pdo->prepare("SELECT * FROM notification_deliveries WHERE status = 'processing' AND locked_by = ? ORDER BY id");
        $q->execute([$token]);
        return $q->fetchAll();
    }

    /** @param array<string,mixed> $d @return string clé de compteur : sent|retried|failed|cancelled|deferred */
    private function process(array $d, DateTimeImmutable $now): string
    {
        $ctx = $this->validate($d, $now);
        if ($ctx === null) {
            $this->finish((int) $d['id'], 'cancelled', null, $now);
            return 'cancelled';
        }
        // heures silencieuses : seuls les canaux qui sonnent ou vibrent sont différés (le centre de notifications reste silencieux)
        if ($d['channel'] !== 'inapp' && ($until = $this->quietUntil((int) $d['user_id'], $now)) !== null) {
            $this->write("UPDATE notification_deliveries SET status = 'pending', attempts = GREATEST(attempts - 1, 0), next_attempt_at = ?, locked_by = NULL, locked_at = NULL WHERE id = ?", [$until->format('Y-m-d H:i:s'), $d['id']]);
            return 'deferred';
        }
        $d['title'] = $ctx['title'];
        $d['body'] = $ctx['body'];
        try {
            $res = $this->senders[$d['channel']]->send($d);
        } catch (\Throwable $e) {
            $res = SendResult::retry('Exception : ' . $e->getMessage());
        }
        if ($res->kind === 'ok') {
            $this->write("UPDATE notification_deliveries SET status = 'sent', sent_at = ?, title = ?, body = ?, locked_by = NULL, locked_at = NULL, last_error = NULL WHERE id = ?", [$now->format('Y-m-d H:i:s'), $ctx['title'], $ctx['body'], $d['id']]);
            if ($d['channel'] === 'inapp') { // le navigateur du destinataire l'apprend par la synchronisation
                $this->write("INSERT INTO sync_changes (calendar_id, entity_type, entity_id, change_type, changed_by, created_at) VALUES (NULL, 'notification', ?, 'created', ?, ?)", [$d['id'], $d['user_id'], $now->format('Y-m-d H:i:s')]);
            }
            return 'sent';
        }
        $max = (int) Config::get('notifications.max_attempts', 5);
        if ($res->kind === 'fail' || (int) $d['attempts'] >= $max) {
            $this->finish((int) $d['id'], 'failed', $res->message, $now);
            return 'failed';
        }
        $back = (array) Config::get('notifications.retry_backoff', [60, 300, 900, 3600, 21600]);
        $wait = (int) ($back[min((int) $d['attempts'], count($back)) - 1] ?? 60);
        $this->write("UPDATE notification_deliveries SET status = 'pending', next_attempt_at = ?, last_error = ?, locked_by = NULL, locked_at = NULL WHERE id = ?", [$now->modify("+$wait seconds")->format('Y-m-d H:i:s'), mb_substr($res->message, 0, 500), $d['id']]);
        return 'retried';
    }

    private function finish(int $id, string $status, ?string $error, DateTimeImmutable $now): void
    {
        $this->write('UPDATE notification_deliveries SET status = ?, last_error = ?, locked_by = NULL, locked_at = NULL WHERE id = ?', [$status, $error === null ? null : mb_substr($error, 0, 500), $id]);
    }

    /**
     * Le rendez-vous existe-t-il encore tel que prévu ? Renvoie le texte à jour, ou null (→ notification annulée).
     * @param array<string,mixed> $d
     * @return array{title:string,body:string}|null
     */
    private function validate(array $d, DateTimeImmutable $now): ?array
    {
        $st = $this->pdo->prepare('SELECT a.*, r.rrule, r.dtstart_local, r.timezone AS rec_tz, u.timezone AS user_tz, rem.offset_minutes
            FROM appointments a LEFT JOIN appointment_recurrence r ON r.appointment_id = a.id JOIN users u ON u.id = ? LEFT JOIN reminders rem ON rem.id = ?
            WHERE a.id = ? AND a.deleted_at IS NULL');
        $st->execute([$d['user_id'], $d['reminder_id'], $d['appointment_id']]);
        $a = $st->fetch();
        if (!$a || $a['offset_minutes'] === null) {
            return null; // supprimé, ou rappel retiré
        }
        $start = $a['start_utc'];
        $end = $a['end_utc'];
        $title = $a['title'];
        $loc = $a['location'];
        $status = $a['status'];
        if ($a['rrule'] !== null) {
            $x = $this->pdo->prepare('SELECT * FROM appointment_exceptions WHERE appointment_id = ?');
            $x->execute([$a['id']]);
            $exc = [];
            foreach ($x->fetchAll() as $e) {
                $exc[$e['original_start_utc']] = $e;
            }
            $o = RecurrenceService::find($a, $exc, $d['occurrence_start_utc']);
            if ($o === null) {
                return null;
            }
            $row = AppointmentRepository::occurrenceRow($a, $o);
            [$start, $end, $title, $loc, $status] = [$row['start_utc'], $row['end_utc'], $row['title'], $row['location'], $row['status']];
        } elseif ($start !== $d['occurrence_start_utc']) {
            return null; // déplacé : un nouveau rappel sera planifié pour la nouvelle heure
        }
        if (in_array($status, ['cancelled', 'completed'], true) || $end <= $now->format('Y-m-d H:i:s') && !$a['all_day']) {
            return null;
        }
        $prefs = $this->settings->notifications((int) $d['user_id']);
        if (!$prefs['enabled']) {
            return null;
        }
        [$t, $b] = NotificationText::make(['user_tz' => $a['user_tz'], 'start' => $start, 'all_day' => (bool) $a['all_day'], 'location' => $loc, 'offset_minutes' => (int) $a['offset_minutes'], 'title' => $title], $prefs['privacy']);
        return ['title' => $t, 'body' => $b];
    }

    /** Fin des heures silencieuses de l'utilisateur si l'instant tombe dedans, sinon null. */
    private function quietUntil(int $userId, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $p = $this->settings->notifications($userId);
        if (!$p['quiet_start'] || !$p['quiet_end'] || $p['quiet_start'] === $p['quiet_end']) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT timezone FROM users WHERE id = ?');
        $st->execute([$userId]);
        $tz = new DateTimeZone((string) ($st->fetchColumn() ?: 'UTC'));
        $local = $now->setTimezone($tz);
        $cur = $local->format('H:i');
        [$s, $e] = [$p['quiet_start'], $p['quiet_end']];
        $inside = $s < $e ? ($cur >= $s && $cur < $e) : ($cur >= $s || $cur < $e);
        if (!$inside) {
            return null;
        }
        $end = $local->setTime((int) substr($e, 0, 2), (int) substr($e, 3, 2));
        if ($end <= $local) {
            $end = $end->modify('+1 day');
        }
        return $end->setTimezone(new DateTimeZone('UTC'));
    }
}
