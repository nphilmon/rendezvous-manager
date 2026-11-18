<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\AppointmentRepository;
use App\Services\AppointmentService;
use App\Services\PermissionService;
use App\Validation\AppointmentValidator;
use DateTimeImmutable;
use DateTimeZone;

final class AppointmentApiController
{
    public const MAX_RANGE_DAYS = 100;
    private const SORTS = ['start', 'title', 'created', 'status', 'priority', 'updated'];

    public function __construct(
        private Authenticator $guard,
        private PermissionService $perms,
        private AppointmentRepository $repo,
        private AppointmentService $service,
    ) {
    }

    /** Mode grille (start+end, sans pagination) ou mode liste (filtres, tri, pagination). */
    public function index(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $q = $req->query;
        $deleted = ($q['deleted'] ?? '') === '1';
        $allowed = $this->perms->readableCalendarIds($u);
        if ($deleted) { // la corbeille n'est visible que là où l'utilisateur peut supprimer
            $allowed = array_values(array_filter($allowed, fn (int $id) => $this->perms->canAccessCalendar($u, $id, 'delete')));
        }
        if (isset($q['calendars']) && $q['calendars'] !== '') {
            $asked = is_string($q['calendars']) ? explode(',', $q['calendars']) : [];
            foreach ($asked as $a) {
                if (!ctype_digit($a)) {
                    throw new HttpException(422, 'Paramètre « calendars » invalide.');
                }
            }
            // l'intersection garantit qu'un calendrier non autorisé n'est jamais lu, sans révéler son existence
            $allowed = array_values(array_intersect($allowed, array_map('intval', $asked)));
        }

        if (isset($q['start']) || isset($q['end'])) {
            $start = self::instant($q['start'] ?? null, 'start');
            $end = self::instant($q['end'] ?? null, 'end');
            if ($end <= $start) {
                throw new HttpException(422, '« end » doit être postérieur à « start ».');
            }
            if (($end->getTimestamp() - $start->getTimestamp()) > self::MAX_RANGE_DAYS * 86400) {
                throw new HttpException(422, 'Période trop longue (' . self::MAX_RANGE_DAYS . ' jours maximum).');
            }
            $res = $this->repo->listRange($allowed, $start, $end);
            return Response::json([
                'data' => array_map([AppointmentRepository::class, 'toApi'], $res['rows']),
                'meta' => ['count' => count($res['rows']), 'truncated' => $res['truncated']],
            ]);
        }

        $f = ['deleted' => $deleted];
        $text = $q['q'] ?? '';
        if (!is_string($text) || mb_strlen($text) > 200) {
            throw new HttpException(422, 'Paramètre « q » invalide (200 caractères maximum).');
        }
        $f['q'] = trim($text);
        foreach (['category_id', 'contact_id', 'created_by'] as $k) {
            if (isset($q[$k]) && $q[$k] !== '') {
                if (!is_string($q[$k]) || !ctype_digit($q[$k])) {
                    throw new HttpException(422, "Paramètre « $k » invalide.");
                }
                $f[$k] = (int) $q[$k];
            }
        }
        foreach (['status' => AppointmentValidator::STATUSES, 'priority' => AppointmentValidator::PRIORITIES] as $k => $valid) {
            if (isset($q[$k]) && $q[$k] !== '') {
                $vals = is_string($q[$k]) ? explode(',', $q[$k]) : [];
                if (!$vals || array_diff($vals, $valid)) {
                    throw new HttpException(422, "Paramètre « $k » invalide.");
                }
                $f[$k] = array_values(array_unique($vals));
            }
        }
        if (($q['mine'] ?? '') === '1') {
            $f['mine_user_id'] = $u->id;
        }
        if (isset($q['from'])) {
            $f['from'] = self::instant($q['from'], 'from');
        }
        if (isset($q['to'])) {
            $f['to'] = self::instant($q['to'], 'to');
        }
        $sort = $q['sort'] ?? 'start';
        $dir = $q['dir'] ?? 'asc';
        if (!is_string($sort) || !in_array($sort, self::SORTS, true) || !in_array($dir, ['asc', 'desc'], true)) {
            throw new HttpException(422, 'Tri invalide.');
        }
        $page = isset($q['page']) ? (is_string($q['page']) && ctype_digit($q['page']) && (int) $q['page'] > 0 ? (int) $q['page'] : 0) : 1;
        $per = isset($q['per_page']) ? (is_string($q['per_page']) && ctype_digit($q['per_page']) ? (int) $q['per_page'] : 0) : 25;
        if ($page < 1 || $per < 1 || $per > 100) {
            throw new HttpException(422, 'Pagination invalide (page ≥ 1, per_page entre 1 et 100).');
        }
        $res = $this->repo->search($allowed, $f, $sort, $dir, $page, $per);
        return Response::json([
            'data' => array_map([AppointmentRepository::class, 'toApi'], $res['rows']),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => $res['total'], 'pages' => max(1, (int) ceil($res['total'] / $per))],
        ]);
    }

    public function show(Request $req, array $p): Response
    {
        $ctx = $this->guard->requirePermission($req, 'appointments.read');
        return Response::json(['data' => $this->service->get($ctx['user'], self::id($p))]);
    }

    public function store(Request $req): Response
    {
        $ctx = $this->guard->require($req);
        return Response::json(['data' => $this->service->create($ctx['user'], $req->json(), $req->ip)], 201);
    }

    public function update(Request $req, array $p): Response
    {
        $ctx = $this->guard->require($req);
        return Response::json(['data' => $this->service->update($ctx['user'], self::id($p), $req->json(), $req->ip)]);
    }

    public function destroy(Request $req, array $p): Response
    {
        $ctx = $this->guard->require($req);
        $this->service->delete($ctx['user'], self::id($p), $req->json(), $req->ip);
        return Response::json(['ok' => true]);
    }

    public function restore(Request $req, array $p): Response
    {
        $ctx = $this->guard->require($req);
        return Response::json(['data' => $this->service->restore($ctx['user'], self::id($p), $req->ip)]);
    }

    public function duplicate(Request $req, array $p): Response
    {
        $ctx = $this->guard->require($req);
        return Response::json(['data' => $this->service->duplicate($ctx['user'], self::id($p), $req->ip)], 201);
    }

    public function cancel(Request $req, array $p): Response
    {
        $ctx = $this->guard->require($req);
        return Response::json(['data' => $this->service->cancel($ctx['user'], self::id($p), $req->json(), $req->ip)]);
    }

    public function complete(Request $req, array $p): Response
    {
        $ctx = $this->guard->require($req);
        return Response::json(['data' => $this->service->complete($ctx['user'], self::id($p), $req->json(), $req->ip)]);
    }

    public function reschedule(Request $req, array $p): Response
    {
        $ctx = $this->guard->require($req);
        return Response::json(['data' => $this->service->reschedule($ctx['user'], self::id($p), $req->json(), $req->ip)]);
    }

    /** @param array<string,string> $p */
    private static function id(array $p): int
    {
        if (!isset($p['id']) || !ctype_digit($p['id']) || (int) $p['id'] < 1) {
            throw new HttpException(404, 'Ressource introuvable.');
        }
        return (int) $p['id'];
    }

    private static function instant(mixed $v, string $name): DateTimeImmutable
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?)?$/', $v)) {
            throw new HttpException(422, "Paramètre « $name » requis (ISO 8601).");
        }
        try {
            return (new DateTimeImmutable($v, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new HttpException(422, "Paramètre « $name » invalide.");
        }
    }
}
