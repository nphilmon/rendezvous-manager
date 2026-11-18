<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CalendarApiController;
use App\Repositories\AppointmentRepository;
use App\Repositories\CalendarRepository;
use App\Services\PermissionService;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\Authenticator;
use App\Repositories\UserRepository;
use App\Services\AuditLogger;
use App\Services\AuthService;

/** Routes de l'API REST v1. Les routes métier sont ajoutées par phase. */
return static function (Router $r): void {
    $auth = static function (): AuthController {
        $pdo = Database::connection();
        $svc = new AuthService($pdo, new UserRepository($pdo), new AuditLogger($pdo));
        return new AuthController($svc, new Authenticator($svc));
    };
    $r->add('POST', '/api/v1/auth/login', static fn (Request $q) => $auth()->login($q));
    $r->add('POST', '/api/v1/auth/logout', static fn (Request $q) => $auth()->logout($q));
    $r->add('GET', '/api/v1/auth/me', static fn (Request $q) => $auth()->me($q));
    $r->add('POST', '/api/v1/auth/password', static fn (Request $q) => $auth()->changePassword($q));

    $cal = static function (): CalendarApiController {
        $pdo = Database::connection();
        $svc = new AuthService($pdo, new UserRepository($pdo), new AuditLogger($pdo));
        return new CalendarApiController(new Authenticator($svc), new PermissionService($pdo), new CalendarRepository($pdo), new AppointmentRepository($pdo));
    };
    $r->add('GET', '/api/v1/calendars', static fn (Request $q) => $cal()->calendars($q));
    $r->add('GET', '/api/v1/appointments', static fn (Request $q) => $cal()->appointments($q));

    $r->add('GET', '/api/v1/health', static function (Request $req): Response {
        $db = 'ok';
        try {
            Database::connection()->query('SELECT 1');
        } catch (\Throwable) {
            $db = 'unavailable'; // détail volontairement non exposé
        }
        $ok = $db === 'ok';
        return Response::json(['status' => $ok ? 'ok' : 'degraded', 'database' => $db], $ok ? 200 : 503);
    });

    $r->add('GET', '/api/v1/version', static fn (): Response => Response::json([
        'name' => Config::get('app.name'),
        'version' => Config::get('app.version'),
    ]));
};
