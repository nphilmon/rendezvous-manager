<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\AppointmentRepository;
use App\Repositories\SettingsRepository;
use App\Services\PermissionService;
use App\Validation\AppointmentValidator;
use App\Validation\Rules;
use PDO;

/**
 * Rappels de l'utilisateur connecté : chacun ne voit et ne modifie que ses propres rappels, sur des rendez-vous d'un calendrier qu'il peut lire.
 * Un rappel = « N minutes avant » ; il est décliné en une ligne par canal activé dans les réglages de l'utilisateur (dans l'application, push, e-mail).
 */
final class ReminderApiController
{
    public function __construct(private Authenticator $guard, private PDO $pdo, private PermissionService $perms, private AppointmentRepository $appointments, private SettingsRepository $settings)
    {
    }

    /** GET /api/v1/reminders?appointment_id=&page=&per_page= */
    public function index(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $ids = $this->perms->readableCalendarIds($u);
        $q = $req->query;
        $page = max(1, (int) ($q['page'] ?? 1));
        $per = isset($q['per_page']) ? (is_string($q['per_page']) && ctype_digit($q['per_page']) ? (int) $q['per_page'] : 0) : 25;
        if ($per < 1 || $per > 100) {
            throw new HttpException(422, 'Pagination invalide (page ≥ 1, per_page entre 1 et 100).');
        }
        $w = ['r.user_id = ?', 'a.deleted_at IS NULL'];
        $args = [$u->id];
        if ($ids === []) {
            return Response::json(['data' => [], 'meta' => ['page' => $page, 'per_page' => $per, 'total' => 0, 'pages' => 1]]);
        }
        $w[] = 'a.calendar_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($args, ...$ids);
        if (isset($q['appointment_id']) && $q['appointment_id'] !== '') {
            $aid = is_string($q['appointment_id']) && ctype_digit($q['appointment_id']) ? (int) $q['appointment_id'] : 0;
            if ($aid < 1) {
                throw new HttpException(422, '« appointment_id » invalide.');
            }
            $w[] = 'r.appointment_id = ?';
            $args[] = $aid;
        }
        $from = ' FROM reminders r JOIN appointments a ON a.id = r.appointment_id WHERE ' . implode(' AND ', $w);
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM (SELECT 1' . $from . ' GROUP BY r.appointment_id, r.offset_minutes) t');
        $st->execute($args);
        $total = (int) $st->fetchColumn();
        $st = $this->pdo->prepare('SELECT r.appointment_id, r.offset_minutes, GROUP_CONCAT(r.channel ORDER BY r.channel) AS channels, a.title, a.start_utc, a.all_day, a.calendar_id' . $from
            . ' GROUP BY r.appointment_id, r.offset_minutes, a.title, a.start_utc, a.all_day, a.calendar_id ORDER BY a.start_utc, r.appointment_id LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
        $st->execute($args);
        $rows = array_map(static fn (array $r) => ['appointment_id' => (int) $r['appointment_id'], 'minutes' => (int) $r['offset_minutes'], 'channels' => explode(',', (string) $r['channels']),
            'appointment' => ['title' => $r['title'], 'start' => str_replace(' ', 'T', $r['start_utc']) . 'Z', 'all_day' => (bool) $r['all_day'], 'calendar_id' => (int) $r['calendar_id']]], $st->fetchAll());
        return Response::json(['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int) max(1, ceil($total / $per))]]);
    }

    /** POST /api/v1/reminders {appointment_id, minutes} : définit (remplace) votre rappel sur ce rendez-vous. */
    public function store(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $b = $req->json();
        $e = [];
        $aid = Rules::id($b['appointment_id'] ?? null);
        if ($aid === null) {
            $e['appointment_id'][] = 'Identifiant de rendez-vous requis.';
        }
        $m = $b['minutes'] ?? null;
        if (!is_int($m) || $m < 0 || $m > AppointmentValidator::MAX_REMINDER_MINUTES) {
            $e['minutes'][] = 'Entier entre 0 et ' . AppointmentValidator::MAX_REMINDER_MINUTES . ' (minutes avant le début) attendu.';
        }
        Rules::throwIf($e);
        $row = $this->appointments->find($aid);
        if ($row === null || !$this->perms->canAccessCalendar($u, (int) $row['calendar_id'], 'read')) {
            throw new HttpException(404, 'Rendez-vous introuvable.'); // l'existence d'un rendez-vous non lisible n'est pas révélée
        }
        $channels = $this->settings->notifications($u->id)['channels'];
        $this->appointments->setReminder($aid, $u->id, $m, $channels);
        return Response::json(['data' => ['appointment_id' => $aid, 'minutes' => $m, 'channels' => $channels]], 201);
    }

    /** DELETE /api/v1/reminders/{appointment_id} : retire votre rappel sur ce rendez-vous. */
    public function destroy(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $aid = Rules::id($p['id'] ?? null) ?? throw new HttpException(404, 'Rappel introuvable.');
        $st = $this->pdo->prepare('SELECT 1 FROM reminders WHERE appointment_id = ? AND user_id = ? LIMIT 1');
        $st->execute([$aid, $u->id]);
        if (!$st->fetchColumn()) {
            throw new HttpException(404, 'Rappel introuvable.');
        }
        $this->appointments->setReminder($aid, $u->id, null);
        return Response::json(['ok' => true]);
    }
}
