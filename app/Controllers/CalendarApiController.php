<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\CalendarRepository;
use App\Services\PermissionService;

final class CalendarApiController
{
    public function __construct(
        private Authenticator $guard,
        private PermissionService $perms,
        private CalendarRepository $calendars,
    ) {
    }

    public function calendars(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $out = [];
        foreach ($this->calendars->listByIds($this->perms->readableCalendarIds($u)) as $c) {
            $id = (int) $c['id'];
            $out[] = [
                'id' => $id, 'name' => $c['name'], 'description' => $c['description'], 'color' => $c['color'], 'kind' => $c['kind'],
                'visibility' => $c['visibility'], 'timezone' => $c['timezone'], 'owner' => $c['owner_name'],
                'mine' => (int) $c['owner_id'] === $u->id,
                'can' => [
                    'create' => $this->perms->canAccessCalendar($u, $id, 'create'),
                    'update' => $this->perms->canAccessCalendar($u, $id, 'update'),
                    'delete' => $this->perms->canAccessCalendar($u, $id, 'delete'),
                    'share' => $this->perms->canAccessCalendar($u, $id, 'share'),
                ],
            ];
        }
        return Response::json(['data' => $out, 'meta' => ['count' => count($out)]]);
    }
}
