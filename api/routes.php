<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\AppointmentApiController;
use App\Controllers\CalendarApiController;
use App\Controllers\CatalogApiController;
use App\Services\AppointmentService;
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
return static function (Router $r, ?\PDO $injected = null): void {
    $auth = static function (): AuthController {
        $pdo = Database::connection();
        $svc = new AuthService($pdo, new UserRepository($pdo), new AuditLogger($pdo));
        return new AuthController($svc, new Authenticator($svc));
    };
    $r->add('POST', '/api/v1/auth/login', static fn (Request $q) => $auth()->login($q));
    $r->add('POST', '/api/v1/auth/logout', static fn (Request $q) => $auth()->logout($q));
    $r->add('GET', '/api/v1/auth/me', static fn (Request $q) => $auth()->me($q));
    $r->add('POST', '/api/v1/auth/password', static fn (Request $q) => $auth()->changePassword($q));

    $deps = static function () use ($injected): array {
        $pdo = $injected ?? Database::connection();
        $svc = new AuthService($pdo, new UserRepository($pdo), new AuditLogger($pdo));
        return [$pdo, new Authenticator($svc), new PermissionService($pdo)];
    };
    $cal = static function () use ($deps): CalendarApiController {
        [$pdo, $guard, $perms] = $deps();
        return new CalendarApiController($guard, $perms, new CalendarRepository($pdo));
    };
    $appt = static function () use ($deps): AppointmentApiController {
        [$pdo, $guard, $perms] = $deps();
        $repo = new AppointmentRepository($pdo);
        return new AppointmentApiController($guard, $perms, $repo, new AppointmentService($repo, $perms, new AuditLogger($pdo)));
    };
    $catalog = static function () use ($deps): CatalogApiController {
        [$pdo, $guard] = $deps();
        return new CatalogApiController($guard, $pdo);
    };
    $r->add('GET', '/api/v1/calendars', static fn (Request $q) => $cal()->calendars($q));
    $r->add('GET', '/api/v1/categories', static fn (Request $q) => $catalog()->categories($q));
    $r->add('GET', '/api/v1/contacts', static fn (Request $q) => $catalog()->contacts($q));
    $r->add('GET', '/api/v1/appointments', static fn (Request $q) => $appt()->index($q));
    $r->add('POST', '/api/v1/appointments', static fn (Request $q) => $appt()->store($q));
    $r->add('GET', '/api/v1/appointments/{id}', static fn (Request $q, array $p) => $appt()->show($q, $p));
    $r->add('PUT', '/api/v1/appointments/{id}', static fn (Request $q, array $p) => $appt()->update($q, $p));
    $r->add('DELETE', '/api/v1/appointments/{id}', static fn (Request $q, array $p) => $appt()->destroy($q, $p));
    foreach (['restore', 'duplicate', 'cancel', 'complete', 'reschedule'] as $action) {
        $r->add('POST', '/api/v1/appointments/{id}/' . $action, static fn (Request $q, array $p) => $appt()->$action($q, $p));
    }

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
