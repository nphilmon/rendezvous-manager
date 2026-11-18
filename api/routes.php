<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\AppointmentApiController;
use App\Controllers\CalendarApiController;
use App\Controllers\CategoryApiController;
use App\Controllers\DashboardApiController;
use App\Controllers\EmailApiController;
use App\Controllers\UserApiController;
use App\Services\UserAdminService;
use App\Controllers\TransferApiController;
use App\Services\Transfer\{ExportService, ImportService};
use App\Repositories\StatsRepository;
use App\Services\DashboardService;
use App\Controllers\NotificationApiController;
use App\Controllers\PushApiController;
use App\Repositories\PushSubscriptionRepository;
use App\Services\Push\EndpointPolicy;
use App\Controllers\SettingsApiController;
use App\Controllers\SyncApiController;
use App\Repositories\NotificationRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SyncRepository;
use App\Controllers\ContactApiController;
use App\Repositories\CategoryRepository;
use App\Repositories\ContactRepository;
use App\Services\ChangeRecorder;
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
    $auth = static function () use ($injected): AuthController {
        $pdo = $injected ?? Database::connection();
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
    $changes = static function () use ($deps): ChangeRecorder {
        [$pdo] = $deps();
        return new ChangeRecorder($pdo, new AuditLogger($pdo));
    };
    $cal = static function () use ($deps, $changes): CalendarApiController {
        [$pdo, $guard, $perms] = $deps();
        return new CalendarApiController($guard, $perms, new CalendarRepository($pdo), $changes());
    };
    $appt = static function () use ($deps): AppointmentApiController {
        [$pdo, $guard, $perms] = $deps();
        $repo = new AppointmentRepository($pdo);
        return new AppointmentApiController($guard, $perms, $repo, new AppointmentService($repo, $perms, new AuditLogger($pdo)));
    };
    $contact = static function () use ($deps, $changes): ContactApiController {
        [$pdo, $guard, $perms] = $deps();
        return new ContactApiController($guard, new ContactRepository($pdo), new AppointmentRepository($pdo), $perms, $changes());
    };
    $category = static function () use ($deps, $changes): CategoryApiController {
        [$pdo, $guard, $perms] = $deps();
        return new CategoryApiController($guard, new CategoryRepository($pdo), $perms, $changes());
    };
    $sync = static function () use ($deps): SyncApiController {
        [$pdo, $guard, $perms] = $deps();
        return new SyncApiController($guard, $perms, new SyncRepository($pdo));
    };
    $r->add('GET', '/api/v1/sync/changes', static fn (Request $q) => $sync()->changes($q));
    $notif = static function () use ($deps): NotificationApiController {
        [$pdo, $guard] = $deps();
        return new NotificationApiController($guard, new NotificationRepository($pdo), $pdo);
    };
    $settingsCtl = static function () use ($deps): SettingsApiController {
        [$pdo, $guard] = $deps();
        return new SettingsApiController($guard, new SettingsRepository($pdo), $pdo);
    };
    $push = static function () use ($deps): PushApiController {
        [$pdo, $guard] = $deps();
        return new PushApiController($guard, new PushSubscriptionRepository($pdo), $pdo, new AuditLogger($pdo), EndpointPolicy::fromConfig());
    };
    $email = static function () use ($deps): EmailApiController {
        [$pdo, $guard] = $deps();
        return new EmailApiController($guard, $pdo, new AuditLogger($pdo));
    };
    $r->add('POST', '/api/v1/email/test', static fn (Request $q) => $email()->test($q));
    $dash = static function () use ($deps): DashboardApiController {
        [$pdo, $guard, $perms] = $deps();
        return new DashboardApiController($guard, new DashboardService($pdo, $perms, new AppointmentRepository($pdo), new StatsRepository($pdo), new NotificationRepository($pdo)));
    };
    $transfer = static function () use ($deps, $changes): TransferApiController {
        [$pdo, $guard, $perms] = $deps();
        $audit = new AuditLogger($pdo);
        $appts = new AppointmentRepository($pdo);
        $contacts = new ContactRepository($pdo);
        return new TransferApiController($guard, new ExportService($pdo, $perms, $appts, $contacts),
            new ImportService($pdo, $perms, new AppointmentService($appts, $perms, $audit), $contacts, new CategoryRepository($pdo), $changes(), $audit), $audit);
    };
    $r->add('GET', '/api/v1/export/appointments', static fn (Request $q) => $transfer()->exportAppointments($q));
    $r->add('GET', '/api/v1/export/contacts', static fn (Request $q) => $transfer()->exportContacts($q));
    $r->add('POST', '/api/v1/import/appointments', static fn (Request $q) => $transfer()->importAppointments($q));
    $r->add('POST', '/api/v1/import/contacts', static fn (Request $q) => $transfer()->importContacts($q));
    $users = static function () use ($deps): UserApiController {
        [$pdo, $guard] = $deps();
        return new UserApiController($guard, new UserAdminService($pdo, new UserRepository($pdo), new AuditLogger($pdo)));
    };
    $r->add('GET', '/api/v1/roles', static fn (Request $q) => $users()->roles($q));
    $r->add('GET', '/api/v1/users', static fn (Request $q) => $users()->index($q));
    $r->add('POST', '/api/v1/users', static fn (Request $q) => $users()->create($q));
    $r->add('GET', '/api/v1/users/{id}', static fn (Request $q, array $p) => $users()->show($q, $p));
    $r->add('PUT', '/api/v1/users/{id}', static fn (Request $q, array $p) => $users()->update($q, $p));
    $r->add('POST', '/api/v1/users/{id}/deactivate', static fn (Request $q, array $p) => $users()->deactivate($q, $p));
    $r->add('POST', '/api/v1/users/{id}/activate', static fn (Request $q, array $p) => $users()->activate($q, $p));
    $r->add('POST', '/api/v1/users/{id}/reset-password', static fn (Request $q, array $p) => $users()->resetPassword($q, $p));
    $r->add('POST', '/api/v1/users/{id}/unlock', static fn (Request $q, array $p) => $users()->unlock($q, $p));
    $r->add('POST', '/api/v1/users/{id}/revoke-sessions', static fn (Request $q, array $p) => $users()->revokeSessions($q, $p));
    $r->add('GET', '/api/v1/dashboard', static fn (Request $q) => $dash()->show($q));
    $r->add('GET', '/api/v1/push/config', static fn (Request $q) => $push()->config($q));
    $r->add('POST', '/api/v1/push/subscribe', static fn (Request $q) => $push()->subscribe($q));
    $r->add('POST', '/api/v1/push/unsubscribe', static fn (Request $q) => $push()->unsubscribe($q));
    $r->add('GET', '/api/v1/push/subscriptions', static fn (Request $q) => $push()->subscriptions($q));
    $r->add('POST', '/api/v1/push/test', static fn (Request $q) => $push()->test($q));
    $r->add('GET', '/api/v1/push/deliveries', static fn (Request $q) => $push()->deliveries($q));
    $r->add('GET', '/api/v1/notifications', static fn (Request $q) => $notif()->index($q));
    $r->add('GET', '/api/v1/notifications/count', static fn (Request $q) => $notif()->count($q));
    $r->add('GET', '/api/v1/notifications/upcoming', static fn (Request $q) => $notif()->upcoming($q));
    $r->add('POST', '/api/v1/notifications/read-all', static fn (Request $q) => $notif()->readAll($q));
    $r->add('POST', '/api/v1/notifications/{id}/read', static fn (Request $q, array $p) => $notif()->read($q, $p));
    $r->add('POST', '/api/v1/notifications/{id}/unread', static fn (Request $q, array $p) => $notif()->unread($q, $p));
    $r->add('GET', '/api/v1/settings', static fn (Request $q) => $settingsCtl()->show($q));
    $r->add('PUT', '/api/v1/settings', static fn (Request $q) => $settingsCtl()->update($q));
    $r->add('GET', '/api/v1/calendars', static fn (Request $q) => $cal()->calendars($q));
    $r->add('POST', '/api/v1/calendars', static fn (Request $q) => $cal()->store($q));
    $r->add('PUT', '/api/v1/calendars/{id}', static fn (Request $q, array $p) => $cal()->update($q, $p));
    $r->add('DELETE', '/api/v1/calendars/{id}', static fn (Request $q, array $p) => $cal()->destroy($q, $p));
    $r->add('GET', '/api/v1/calendars/{id}/permissions', static fn (Request $q, array $p) => $cal()->permissions($q, $p));
    $r->add('PUT', '/api/v1/calendars/{id}/permissions', static fn (Request $q, array $p) => $cal()->setPermissions($q, $p));
    $r->add('GET', '/api/v1/users/lookup', static fn (Request $q) => $cal()->lookupUsers($q));
    $r->add('GET', '/api/v1/categories', static fn (Request $q) => $category()->index($q));
    $r->add('POST', '/api/v1/categories', static fn (Request $q) => $category()->store($q));
    $r->add('PUT', '/api/v1/categories/{id}', static fn (Request $q, array $p) => $category()->update($q, $p));
    $r->add('DELETE', '/api/v1/categories/{id}', static fn (Request $q, array $p) => $category()->destroy($q, $p));
    $r->add('GET', '/api/v1/contacts', static fn (Request $q) => $contact()->index($q));
    $r->add('POST', '/api/v1/contacts', static fn (Request $q) => $contact()->store($q));
    $r->add('GET', '/api/v1/contacts/{id}', static fn (Request $q, array $p) => $contact()->show($q, $p));
    $r->add('PUT', '/api/v1/contacts/{id}', static fn (Request $q, array $p) => $contact()->update($q, $p));
    $r->add('DELETE', '/api/v1/contacts/{id}', static fn (Request $q, array $p) => $contact()->destroy($q, $p));
    $r->add('GET', '/api/v1/contacts/{id}/appointments', static fn (Request $q, array $p) => $contact()->appointments($q, $p));
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
