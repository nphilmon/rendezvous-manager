<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\AppointmentRepository;
use App\Repositories\CalendarRepository;
use App\Services\PermissionService;
use DateTimeImmutable;
use DateTimeZone;

final class CalendarApiController
{
    public const MAX_RANGE_DAYS = 100;

    public function __construct(
        private Authenticator $guard,
        private PermissionService $perms,
        private CalendarRepository $calendars,
        private AppointmentRepository $appointments,
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

    public function appointments(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $start = self::parseInstant($req->query['start'] ?? null, 'start');
        $end = self::parseInstant($req->query['end'] ?? null, 'end');
        if ($end <= $start) {
            throw new HttpException(422, '« end » doit être postérieur à « start ».');
        }
        if (($end->getTimestamp() - $start->getTimestamp()) > self::MAX_RANGE_DAYS * 86400) {
            throw new HttpException(422, 'Période trop longue (' . self::MAX_RANGE_DAYS . ' jours maximum).');
        }
        $allowed = $this->perms->readableCalendarIds($u);
        if (isset($req->query['calendars']) && $req->query['calendars'] !== '') {
            $asked = is_string($req->query['calendars']) ? explode(',', $req->query['calendars']) : [];
            foreach ($asked as $a) {
                if (!ctype_digit($a)) {
                    throw new HttpException(422, 'Paramètre « calendars » invalide.');
                }
            }
            // l'intersection garantit qu'un calendrier non autorisé n'est jamais lu, sans révéler son existence
            $allowed = array_values(array_intersect($allowed, array_map('intval', $asked)));
        }
        $res = $this->appointments->listRange($allowed, $start, $end);
        return Response::json([
            'data' => array_map([AppointmentRepository::class, 'toApi'], $res['rows']),
            'meta' => ['count' => count($res['rows']), 'truncated' => $res['truncated']],
        ]);
    }

    private static function parseInstant(mixed $v, string $name): DateTimeImmutable
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
