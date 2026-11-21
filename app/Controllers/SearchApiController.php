<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\AppointmentRepository;
use App\Repositories\ContactRepository;
use App\Services\PermissionService;
use PDO;

/** Recherche globale (rendez-vous et contacts autorisés) et valeurs proposées par les filtres. Rien n'est renvoyé hors des calendriers lisibles. */
final class SearchApiController
{
    public function __construct(private Authenticator $guard, private PDO $pdo, private PermissionService $perms, private AppointmentRepository $appointments, private ContactRepository $contacts)
    {
    }

    /** GET /api/v1/search?q= (2 à 100 caractères) : au plus 8 rendez-vous et 8 contacts, avec le nombre total de chaque. */
    public function search(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $q = $req->query['q'] ?? '';
        if (!is_string($q) || mb_strlen(trim($q)) < 2 || mb_strlen($q) > 100) {
            throw new HttpException(422, 'Saisissez entre 2 et 100 caractères.');
        }
        $q = trim($q);
        $a = $this->appointments->search($this->perms->readableCalendarIds($u), ['q' => $q], 'start', 'desc', 1, 8);
        $c = $u->can('contacts.read') ? $this->contacts->search($u->can('calendars.manage_all') ? null : $u->id, $q, 'name', 'asc', 1, 8) : ['rows' => [], 'total' => 0];
        return Response::json(['data' => [
            'appointments' => ['total' => $a['total'], 'items' => array_map([AppointmentRepository::class, 'toApi'], $a['rows'])],
            'contacts' => ['total' => $c['total'], 'items' => array_map(fn ($r) => ContactRepository::toApi($r, $u->id), $c['rows'])],
        ]]);
    }

    /** GET /api/v1/search/facets : créateurs des rendez-vous des calendriers lisibles (filtre « créé par »). */
    public function facets(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $ids = $this->perms->readableCalendarIds($u);
        $creators = [];
        if ($ids !== []) {
            $st = $this->pdo->prepare('SELECT DISTINCT u.id, u.first_name, u.last_name FROM appointments a JOIN users u ON u.id = a.created_by
                WHERE a.deleted_at IS NULL AND a.calendar_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY u.last_name, u.first_name, u.id LIMIT 200');
            $st->execute($ids);
            $creators = array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name'])], $st->fetchAll());
        }
        return Response::json(['data' => ['creators' => $creators]]);
    }
}
