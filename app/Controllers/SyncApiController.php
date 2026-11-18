<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\SyncRepository;
use App\Services\PermissionService;

/**
 * Synchronisation incrémentale par curseur serveur (interrogation périodique, 30 s par défaut).
 * GET /api/v1/sync/changes?cursor=N → { cursor, has_more, reset, access, poll_interval, changes: [...] }.
 * Sans curseur : renvoie le curseur courant, sans modification (point de départ après un chargement complet).
 */
final class SyncApiController
{
    public function __construct(private Authenticator $guard, private PermissionService $perms, private SyncRepository $sync)
    {
    }

    public function changes(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $ids = $this->perms->readableCalendarIds($u);
        $max = $this->sync->maxId();
        $base = [
            'access' => $this->perms->accessToken($u), // change quand l'ensemble des calendriers lisibles change
            'poll_interval' => (int) Config::get('app.sync.interval', 30),
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $raw = $req->query['cursor'] ?? null;
        if ($raw === null || $raw === '') {
            return Response::json($base + ['cursor' => $max, 'has_more' => false, 'reset' => false, 'changes' => []]);
        }
        if (!is_string($raw) || !ctype_digit($raw) || strlen($raw) > 18) {
            throw new HttpException(422, 'Curseur invalide.');
        }
        $cursor = (int) $raw;
        if ($cursor > $max || $cursor < $this->sync->floor()) {
            // curseur inconnu (base restaurée ?) ou purgé : le client doit recharger ses données
            return Response::json($base + ['cursor' => $max, 'has_more' => false, 'reset' => true, 'changes' => []]);
        }
        $limit = (int) Config::get('app.sync.max_changes', 500);
        $rows = $this->sync->changesSince($cursor, $ids, $u->id, $u->can('calendars.manage_all'), $limit);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $next = $more ? (int) end($rows)['id'] : $max;
        $changes = array_map(fn ($r) => [
            'id' => (int) $r['id'], 'entity' => $r['entity_type'], 'entity_id' => (int) $r['entity_id'], 'change' => $r['change_type'],
            'calendar_id' => $r['calendar_id'] === null ? null : (int) $r['calendar_id'], 'by_me' => (int) $r['changed_by'] === $u->id,
            'at' => str_replace(' ', 'T', $r['created_at']) . 'Z',
        ], $rows);
        return Response::json($base + ['cursor' => $next, 'has_more' => $more, 'reset' => false, 'changes' => $changes]);
    }
}
