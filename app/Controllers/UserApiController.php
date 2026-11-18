<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Services\UserAdminService;
use App\Validation\Rules;

/** /api/v1/users et /api/v1/roles : réservé au droit « users.manage ». Les mots de passe provisoires ne sont renvoyés qu'une fois (Cache-Control: no-store). */
final class UserApiController
{
    private const SORTS = ['name', 'username', 'role', 'last_login', 'created'];

    public function __construct(private Authenticator $guard, private UserAdminService $service)
    {
    }

    public function roles(Request $req): Response
    {
        $this->guard->requirePermission($req, 'users.manage');
        return Response::json(['data' => $this->service->roles()]);
    }

    public function index(Request $req): Response
    {
        $this->guard->requirePermission($req, 'users.manage');
        $q = $req->query;
        $str = static fn (string $k): string => isset($q[$k]) && is_string($q[$k]) ? mb_substr(trim($q[$k]), 0, 100) : '';
        $active = $str('active');
        if ($active !== '' && !in_array($active, ['0', '1'], true)) {
            throw new HttpException(422, '« active » : 0 ou 1.');
        }
        $sort = $str('sort') ?: 'name';
        if (!in_array($sort, self::SORTS, true)) {
            throw new HttpException(422, 'Tri invalide (' . implode(', ', self::SORTS) . ').');
        }
        $page = max(1, (int) ($q['page'] ?? 1));
        $per = min(100, max(1, (int) ($q['per_page'] ?? 25)));
        $res = $this->service->search(['q' => $str('q'), 'role' => $str('role'), 'active' => $active], $sort, ($q['dir'] ?? '') === 'desc' ? 'desc' : 'asc', $page, $per);
        return Response::json(['data' => $res['rows'], 'meta' => ['total' => $res['total'], 'page' => $page, 'per_page' => $per, 'pages' => (int) max(1, ceil($res['total'] / $per))]]);
    }

    public function show(Request $req, array $p): Response
    {
        $this->guard->requirePermission($req, 'users.manage');
        return Response::json(['data' => $this->service->get($this->id($p))]);
    }

    public function create(Request $req): Response
    {
        $admin = $this->guard->requirePermission($req, 'users.manage')['user'];
        $r = $this->service->create($admin, $req->json(), $req->ip);
        return Response::json(['data' => $r['user'], 'temporary_password' => $r['temporary_password']], 201);
    }

    public function update(Request $req, array $p): Response
    {
        $admin = $this->guard->requirePermission($req, 'users.manage')['user'];
        return Response::json(['data' => $this->service->update($admin, $this->id($p), $req->json(), $req->ip)]);
    }

    public function deactivate(Request $req, array $p): Response
    {
        $admin = $this->guard->requirePermission($req, 'users.manage')['user'];
        return Response::json(['data' => $this->service->setActive($admin, $this->id($p), false, $req->ip)]);
    }

    public function activate(Request $req, array $p): Response
    {
        $admin = $this->guard->requirePermission($req, 'users.manage')['user'];
        return Response::json(['data' => $this->service->setActive($admin, $this->id($p), true, $req->ip)]);
    }

    /** POST {password?} : sans mot de passe fourni, un mot de passe provisoire est généré. */
    public function resetPassword(Request $req, array $p): Response
    {
        $admin = $this->guard->requirePermission($req, 'users.manage')['user'];
        $b = $req->json();
        $pw = $b['password'] ?? null;
        if ($pw !== null && !is_string($pw)) {
            throw new HttpException(422, 'Mot de passe : texte attendu.');
        }
        $r = $this->service->resetPassword($admin, $this->id($p), $pw, $req->ip);
        return Response::json(['data' => $r['user'], 'temporary_password' => $r['temporary_password']]);
    }

    public function unlock(Request $req, array $p): Response
    {
        $admin = $this->guard->requirePermission($req, 'users.manage')['user'];
        return Response::json(['data' => $this->service->unlock($admin, $this->id($p), $req->ip)]);
    }

    public function revokeSessions(Request $req, array $p): Response
    {
        $admin = $this->guard->requirePermission($req, 'users.manage')['user'];
        return Response::json(['data' => $this->service->revokeAll($admin, $this->id($p), $req->ip)]);
    }

    /** @param array<string,string> $p */
    private function id(array $p): int
    {
        return Rules::id($p['id'] ?? null) ?? throw new HttpException(404, 'Utilisateur introuvable.');
    }
}
