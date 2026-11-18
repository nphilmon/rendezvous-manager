<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Models\AuthUser;
use App\Services\AuthService;

final class Authenticator
{
    private const UNSAFE = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private AuthService $auth)
    {
    }

    /**
     * Exige une session valide. Pour les méthodes modifiantes, exige aussi le jeton CSRF
     * (en-tête X-CSRF-Token) et, si présent, un en-tête Origin de même hôte.
     * @return array{user:AuthUser, csrf:string, session_id:string}
     */
    public function require(Request $req): array
    {
        $ctx = $this->auth->authenticate($req->cookie((string) Config::get('app.auth.cookie_name', 'rvm_session')));
        if ($ctx === null) {
            throw new HttpException(401, 'Authentification requise.');
        }
        if (in_array($req->method, self::UNSAFE, true)) {
            $sent = $req->header('X-CSRF-Token') ?? '';
            if ($ctx['csrf'] === '' || !hash_equals($ctx['csrf'], $sent)) {
                throw new HttpException(403, 'Jeton CSRF invalide.');
            }
            $origin = $req->header('Origin');
            if ($origin !== null && $origin !== 'null') {
                $host = (string) parse_url($origin, PHP_URL_HOST);
                $port = parse_url($origin, PHP_URL_PORT);
                $originHost = $host . ($port ? ':' . $port : '');
                if (strcasecmp($originHost, (string) $req->header('Host')) !== 0) {
                    throw new HttpException(403, 'Origine non autorisée.');
                }
            }
        }
        return $ctx;
    }

    public function requirePermission(Request $req, string $permission): array
    {
        $ctx = $this->require($req);
        if (!$ctx['user']->can($permission)) {
            throw new HttpException(403, 'Permission insuffisante.');
        }
        return $ctx;
    }
}
