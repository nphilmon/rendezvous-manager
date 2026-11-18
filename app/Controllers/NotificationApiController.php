<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\NotificationRepository;
use App\Repositories\SettingsRepository;
use App\Services\Notifications\ReminderPlanner;
use App\Validation\Rules;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class NotificationApiController
{
    public function __construct(private Authenticator $guard, private NotificationRepository $repo, private PDO $pdo)
    {
    }

    /** filter : unread | read | all (historique). */
    public function index(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        $filter = $req->query['filter'] ?? 'all';
        if (!is_string($filter) || !in_array($filter, ['unread', 'read', 'all'], true)) {
            throw new HttpException(422, 'Filtre invalide (unread, read, all).');
        }
        $page = isset($req->query['page']) ? Rules::id($req->query['page']) : 1;
        $per = isset($req->query['per_page']) ? Rules::id($req->query['per_page']) : 20;
        if ($page === null || $per === null || $per > 100) {
            throw new HttpException(422, 'Pagination invalide.');
        }
        $res = $this->repo->list($u->id, $filter, $page, $per);
        return Response::json([
            'data' => array_map([NotificationRepository::class, 'toApi'], $res['rows']),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => $res['total'], 'pages' => max(1, (int) ceil($res['total'] / $per)), 'unread' => $this->repo->unreadCount($u->id)],
        ]);
    }

    public function count(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        return Response::json(['unread' => $this->repo->unreadCount($u->id)]);
    }

    /** Rappels à venir (7 jours), calculés à la demande : rien n'est encore envoyé. */
    public function upcoming(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        $planner = new ReminderPlanner($this->pdo, new SettingsRepository($this->pdo));
        $rows = $planner->upcoming($u->id, new DateTimeImmutable('now', new DateTimeZone('UTC')), 7);
        $iso = static fn (string $d): string => str_replace(' ', 'T', $d) . 'Z';
        return Response::json(['data' => array_map(fn ($r) => ['appointment_id' => $r['appointment_id'], 'occurrence_start' => $iso($r['occurrence_start']), 'trigger_at' => $iso($r['trigger_at']),
            'start' => $iso($r['start']), 'offset_minutes' => $r['offset_minutes'], 'title' => $r['title'], 'body' => $r['body'], 'recurring' => $r['recurring']], $rows)]);
    }

    /** @param array<string,string> $p */
    public function read(Request $req, array $p): Response
    {
        return $this->mark($req, $p, true);
    }

    /** @param array<string,string> $p */
    public function unread(Request $req, array $p): Response
    {
        return $this->mark($req, $p, false);
    }

    /** @param array<string,string> $p */
    private function mark(Request $req, array $p, bool $read): Response
    {
        $u = $this->guard->require($req)['user'];
        $id = Rules::id($p['id'] ?? null) ?? throw new HttpException(404, 'Notification introuvable.');
        if (!$this->repo->setRead($u->id, $id, $read)) {
            throw new HttpException(404, 'Notification introuvable.'); // y compris celles d'autres utilisateurs
        }
        return Response::json(['ok' => true, 'unread' => $this->repo->unreadCount($u->id)]);
    }

    public function readAll(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        return Response::json(['ok' => true, 'updated' => $this->repo->markAllRead($u->id), 'unread' => 0]);
    }
}
