<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\AppointmentRepository;
use App\Repositories\AuditRepository;
use App\Services\PermissionService;
use App\Validation\Rules;
use DateTimeImmutable;
use DateTimeZone;

/** Journal d'audit complet (droit « audit.view ») et historique d'un rendez-vous (droit de lecture du calendrier). */
final class AuditApiController
{
    public function __construct(private Authenticator $guard, private AuditRepository $audit, private AppointmentRepository $appointments, private PermissionService $perms)
    {
    }

    /** GET /api/v1/audit?action=&user_id=&entity_type=&entity_id=&from=&to=&q=&page=&per_page= */
    public function index(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'audit.view')['user'];
        $q = $req->query;
        $str = static fn (string $k): string => isset($q[$k]) && is_string($q[$k]) ? mb_substr(trim($q[$k]), 0, 100) : '';
        $f = ['action' => $str('action'), 'entity_type' => $str('entity_type'), 'q' => $str('q')];
        if ($f['action'] !== '' && !preg_match('/^[a-z_]+(\.[a-z_]*)?$/', $f['action'])) {
            throw new HttpException(422, 'Action invalide.');
        }
        if ($f['entity_type'] !== '' && !preg_match('/^[a-z_]{1,40}$/', $f['entity_type'])) {
            throw new HttpException(422, 'Type d\'entité invalide.');
        }
        foreach (['user_id', 'entity_id'] as $k) {
            $v = $str($k);
            if ($v !== '' && !ctype_digit($v)) {
                throw new HttpException(422, "« $k » invalide.");
            }
            $f[$k] = $v;
        }
        $tz = new DateTimeZone($u->timezone);
        $day = static function (string $v, string $name, bool $end) use ($tz): ?string {
            if ($v === '') {
                return null;
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new HttpException(422, "« $name » : date AAAA-MM-JJ attendue.");
            }
            $d = new DateTimeImmutable($v . ' 00:00:00', $tz);
            return ($end ? $d->modify('+1 day') : $d)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        };
        $f['from'] = $day($str('from'), 'from', false);
        $f['to'] = $day($str('to'), 'to', true);
        $page = max(1, (int) ($q['page'] ?? 1));
        $per = min(100, max(1, (int) ($q['per_page'] ?? 50)));
        $res = $this->audit->search($f, $page, $per);
        return Response::json(['data' => array_map(static fn ($r) => AuditRepository::present($r, true), $res['rows']),
            'meta' => ['total' => $res['total'], 'page' => $page, 'per_page' => $per, 'pages' => (int) max(1, ceil($res['total'] / $per)), 'actions' => AuditRepository::actions()]]);
    }

    /** GET /api/v1/appointments/{id}/history : sans adresse IP ; 404 si le calendrier n'est pas lisible. */
    public function appointmentHistory(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $id = Rules::id($p['id'] ?? null) ?? throw new HttpException(404, 'Rendez-vous introuvable.');
        $row = $this->appointments->find($id, true);
        if (!$row || !$this->perms->canAccessCalendar($u, (int) $row['calendar_id'], 'read')) {
            throw new HttpException(404, 'Rendez-vous introuvable.');
        }
        return Response::json(['data' => array_map(static fn ($r) => AuditRepository::present($r, false), $this->audit->forEntity('appointment', $id))]);
    }
}
