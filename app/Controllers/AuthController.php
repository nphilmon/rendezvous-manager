<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Services\AuthService;

final class AuthController
{
    public function __construct(private AuthService $auth, private Authenticator $guard)
    {
    }

    private static function cookie(string $value, int $expires): string
    {
        $name = (string) Config::get('app.auth.cookie_name', 'rvm_session');
        $secure = Config::get('app.auth.cookie_secure', true) ? '; Secure' : '';
        $maxAge = max(0, $expires - time());
        return sprintf(
            '%s=%s; Path=/; Expires=%s; Max-Age=%d; HttpOnly; SameSite=Lax%s',
            $name, $value, gmdate('D, d M Y H:i:s \G\M\T', $expires), $maxAge, $secure
        );
    }

    public function login(Request $req): Response
    {
        $b = $req->json();
        $id = $b['identifier'] ?? null;
        $pw = $b['password'] ?? null;
        if (!is_string($id) || !is_string($pw) || $id === '' || $pw === '' || mb_strlen($id) > 190 || strlen($pw) > 1024) {
            throw new HttpException(422, 'Identifiant et mot de passe requis.');
        }
        try {
            $r = $this->auth->login($id, $pw, $req->ip, (string) $req->header('User-Agent'));
        } catch (HttpException $e) {
            if ($e->status === 429) {
                return Response::json(['error' => ['status' => 429, 'message' => $e->getMessage()]], 429,
                    ['Retry-After' => (string) ($e->details['retry_after'] ?? 900)]);
            }
            throw $e;
        }
        return Response::json(['user' => $r['user']->toArray(), 'csrf_token' => $r['csrf']], 200,
            ['Set-Cookie' => self::cookie($r['token'], $r['expires_at'])]);
    }

    public function logout(Request $req): Response
    {
        $ctx = $this->guard->require($req);
        $this->auth->logout($ctx['session_id'], $ctx['user'], $req->ip);
        return Response::json(['ok' => true], 200, ['Set-Cookie' => self::cookie('', time() - 3600)]);
    }

    public function me(Request $req): Response
    {
        $ctx = $this->guard->require($req);
        return Response::json(['user' => $ctx['user']->toArray(), 'csrf_token' => $ctx['csrf']]);
    }

    public function changePassword(Request $req): Response
    {
        $ctx = $this->guard->require($req);
        $b = $req->json();
        if (!is_string($b['current_password'] ?? null) || !is_string($b['new_password'] ?? null)) {
            throw new HttpException(422, 'Mots de passe requis.');
        }
        $this->auth->changePassword($ctx['user'], $ctx['session_id'], $b['current_password'], $b['new_password'], $req->ip);
        return Response::json(['ok' => true]);
    }
}
