<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Models\AuthUser;
use App\Repositories\AppointmentRepository;
use App\Repositories\ContactRepository;
use App\Services\ChangeRecorder;
use App\Services\PermissionService;
use App\Validation\Rules;

final class ContactApiController
{
    public function __construct(
        private Authenticator $guard,
        private ContactRepository $contacts,
        private AppointmentRepository $appointments,
        private PermissionService $perms,
        private ChangeRecorder $changes,
    ) {
    }

    /** Un utilisateur gère ses propres contacts ; calendars.manage_all voit et gère ceux de tous. */
    private function scopeOwner(AuthUser $u): ?int
    {
        return $u->can('calendars.manage_all') ? null : $u->id;
    }

    /** @return array<string,mixed> */
    private function owned(AuthUser $u, array $p): array
    {
        $id = Rules::id($p['id'] ?? null) ?? throw new HttpException(404, 'Contact introuvable.');
        $c = $this->contacts->find($id);
        if (!$c || ($this->scopeOwner($u) !== null && (int) $c['owner_id'] !== $u->id)) {
            throw new HttpException(404, 'Contact introuvable.');
        }
        return $c;
    }

    public function index(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'contacts.read')['user'];
        $q = $req->query;
        $text = $q['q'] ?? '';
        if (!is_string($text) || mb_strlen($text) > 200) {
            throw new HttpException(422, 'Paramètre « q » invalide.');
        }
        $sort = $q['sort'] ?? 'name';
        $dir = $q['dir'] ?? 'asc';
        if (!in_array($sort, ['name', 'company', 'created', 'updated'], true) || !in_array($dir, ['asc', 'desc'], true)) {
            throw new HttpException(422, 'Tri invalide.');
        }
        $page = isset($q['page']) ? Rules::id($q['page']) : 1;
        $per = isset($q['per_page']) ? Rules::id($q['per_page']) : 25;
        if ($page === null || $per === null || $per > 500) {
            throw new HttpException(422, 'Pagination invalide (page ≥ 1, per_page entre 1 et 500).');
        }
        $res = $this->contacts->search($this->scopeOwner($u), trim($text), $sort, $dir, $page, $per);
        return Response::json([
            'data' => array_map(fn ($r) => ContactRepository::toApi($r, $u->id), $res['rows']),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => $res['total'], 'pages' => max(1, (int) ceil($res['total'] / $per))],
        ]);
    }

    public function show(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'contacts.read')['user'];
        return Response::json(['data' => ContactRepository::toApi($this->owned($u, $p), $u->id)]);
    }

    public function store(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'contacts.write')['user'];
        $d = self::validate($req->json());
        $id = $this->contacts->insert($u->id, $d);
        $this->changes->record('contact', $id, 'created', $u->id, $req->ip);
        return Response::json(['data' => ContactRepository::toApi($this->contacts->find($id), $u->id)], 201);
    }

    public function update(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'contacts.write')['user'];
        $c = $this->owned($u, $p);
        $d = self::validate($req->json(), $c);
        $this->contacts->update((int) $c['id'], $d);
        $this->changes->record('contact', (int) $c['id'], 'updated', $u->id, $req->ip);
        return Response::json(['data' => ContactRepository::toApi($this->contacts->find((int) $c['id']), $u->id)]);
    }

    public function destroy(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'contacts.write')['user'];
        $c = $this->owned($u, $p);
        $this->contacts->softDelete((int) $c['id']);
        $this->changes->record('contact', (int) $c['id'], 'deleted', $u->id, $req->ip);
        return Response::json(['ok' => true]);
    }

    /** Rendez-vous liés au contact, limités aux calendriers lisibles par l'utilisateur. */
    public function appointments(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'contacts.read')['user'];
        $c = $this->owned($u, $p);
        $page = isset($req->query['page']) ? Rules::id($req->query['page']) : 1;
        if ($page === null) {
            throw new HttpException(422, 'Pagination invalide.');
        }
        $res = $this->appointments->search($this->perms->readableCalendarIds($u), ['contact_id' => (int) $c['id']], 'start', 'desc', $page, 25);
        return Response::json([
            'data' => array_map([AppointmentRepository::class, 'toApi'], $res['rows']),
            'meta' => ['page' => $page, 'per_page' => 25, 'total' => $res['total'], 'pages' => max(1, (int) ceil($res['total'] / 25))],
        ]);
    }

    /**
     * @param array<string,mixed> $in @param array<string,mixed>|null $existing champs omis conservés à la modification
     * @return array<string,mixed>
     */
    public static function validate(array $in, ?array $existing = null): array
    {
        if ($existing !== null) {
            $in += array_intersect_key($existing, array_flip(['last_name', 'first_name', 'company', 'phone', 'email', 'address', 'notes']));
        }
        $e = [];
        $d = [
            'last_name' => Rules::text($in, 'last_name', 80, true, $e),
            'first_name' => Rules::text($in, 'first_name', 80, false, $e),
            'company' => Rules::text($in, 'company', 120, false, $e),
            'phone' => Rules::phone($in, 'phone', $e),
            'email' => Rules::email($in, 'email', $e),
            'address' => Rules::text($in, 'address', 255, false, $e),
            'notes' => Rules::text($in, 'notes', 10000, false, $e),
        ];
        Rules::throwIf($e);
        return $d;
    }
}
