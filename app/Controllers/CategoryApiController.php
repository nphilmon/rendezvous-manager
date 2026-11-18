<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\CategoryRepository;
use App\Services\ChangeRecorder;
use App\Services\PermissionService;
use App\Validation\Rules;

/** Catégories globales, indépendantes des calendriers : lecture pour tous, gestion avec categories.manage. */
final class CategoryApiController
{
    public function __construct(
        private Authenticator $guard,
        private CategoryRepository $categories,
        private PermissionService $perms,
        private ChangeRecorder $changes,
    ) {
    }

    public function index(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        return Response::json(['data' => $this->categories->all($this->perms->readableCalendarIds($u)), 'meta' => ['can_manage' => $u->can('categories.manage')]]);
    }

    public function store(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'categories.manage')['user'];
        [$name, $color] = $this->validate($req->json(), null);
        $id = $this->categories->insert($name, $color, $u->id);
        $this->changes->record('category', $id, 'created', $u->id, $req->ip);
        return Response::json(['data' => ['id' => $id, 'name' => $name, 'color' => $color, 'appointment_count' => 0]], 201);
    }

    public function update(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'categories.manage')['user'];
        $c = $this->find($p);
        [$name, $color] = $this->validate($req->json() + ['name' => $c['name'], 'color' => $c['color']], (int) $c['id']);
        $this->categories->update((int) $c['id'], $name, $color);
        $this->changes->record('category', (int) $c['id'], 'updated', $u->id, $req->ip);
        return Response::json(['data' => ['id' => (int) $c['id'], 'name' => $name, 'color' => $color]]);
    }

    public function destroy(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'categories.manage')['user'];
        $c = $this->find($p);
        $detached = $this->categories->softDelete((int) $c['id']);
        $this->changes->record('category', (int) $c['id'], 'deleted', $u->id, $req->ip);
        return Response::json(['ok' => true, 'detached' => $detached]);
    }

    /** @return array<string,mixed> */
    private function find(array $p): array
    {
        $id = Rules::id($p['id'] ?? null) ?? throw new HttpException(404, 'Catégorie introuvable.');
        return $this->categories->find($id) ?? throw new HttpException(404, 'Catégorie introuvable.');
    }

    /** @param array<string,mixed> $in @return array{0:string,1:string} */
    private function validate(array $in, ?int $exceptId): array
    {
        $e = [];
        $name = Rules::text($in, 'name', 80, true, $e);
        $color = Rules::color($in, 'color', false, $e) ?? '#64748b';
        if ($name !== null && !isset($e['name']) && $this->categories->nameTaken($name, $exceptId)) {
            $e['name'][] = 'Une catégorie porte déjà ce nom.';
        }
        Rules::throwIf($e);
        return [$name, $color];
    }
}
