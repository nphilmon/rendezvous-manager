<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\CalendarRepository;
use App\Models\AuthUser;
use App\Services\ChangeRecorder;
use App\Services\PermissionService;
use App\Validation\Rules;
use DateTimeZone;

final class CalendarApiController
{
    public function __construct(
        private Authenticator $guard,
        private PermissionService $perms,
        private CalendarRepository $calendars,
        private ?ChangeRecorder $changes = null,
    ) {
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function present(array $c, AuthUser $u): array
    {
        $id = (int) $c['id'];
        $manage = (int) $c['owner_id'] === $u->id || $u->can('calendars.manage_all');
        return [
            'id' => $id, 'name' => $c['name'], 'description' => $c['description'], 'color' => $c['color'], 'kind' => $c['kind'],
            'visibility' => $c['visibility'], 'timezone' => $c['timezone'], 'owner' => $c['owner_name'], 'version' => (int) ($c['version'] ?? 1),
            'mine' => (int) $c['owner_id'] === $u->id,
            'can' => [
                'create' => $this->perms->canAccessCalendar($u, $id, 'create'),
                'update' => $this->perms->canAccessCalendar($u, $id, 'update'),
                'delete' => $this->perms->canAccessCalendar($u, $id, 'delete'),
                'share' => $this->perms->canAccessCalendar($u, $id, 'share'),
                'manage' => $manage && $u->can('calendars.create'),
            ],
        ];
    }

    public function calendars(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $out = array_map(fn ($c) => $this->present($c, $u), $this->calendars->listByIds($this->perms->readableCalendarIds($u), true));
        return Response::json(['data' => $out, 'meta' => ['count' => count($out), 'can_create' => $u->can('calendars.create')]]);
    }

    /** @param array<string,mixed> $in @param array<string,mixed>|null $existing @return array<string,mixed> */
    private static function validate(array $in, ?array $existing): array
    {
        if ($existing !== null) {
            $in += array_intersect_key($existing, array_flip(['name', 'description', 'color', 'kind', 'visibility', 'timezone']));
        }
        $e = [];
        $d = [
            'name' => Rules::text($in, 'name', 120, true, $e),
            'description' => Rules::text($in, 'description', 2000, false, $e),
            'color' => Rules::color($in, 'color', false, $e) ?? '#3b82f6',
            'kind' => Rules::enum($in, 'kind', ['personal', 'professional', 'team', 'training', 'administrative', 'other'], 'personal', $e),
            'visibility' => Rules::enum($in, 'visibility', ['private', 'shared', 'organization'], 'private', $e),
            'timezone' => $in['timezone'] ?? 'Europe/Paris',
        ];
        if (!is_string($d['timezone']) || !in_array($d['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $e['timezone'][] = 'Fuseau horaire invalide.';
        }
        Rules::throwIf($e);
        return $d;
    }

    /** @return array<string,mixed> calendrier modifiable (propriétaire ou calendars.manage_all), sinon 404/403 */
    private function manageable(AuthUser $u, array $p): array
    {
        $id = Rules::id($p['id'] ?? null) ?? throw new HttpException(404, 'Calendrier introuvable.');
        $c = $this->calendars->find($id);
        if (!$c || !$this->perms->canAccessCalendar($u, $id, 'read')) {
            throw new HttpException(404, 'Calendrier introuvable.');
        }
        if ((int) $c['owner_id'] !== $u->id && !$u->can('calendars.manage_all')) {
            throw new HttpException(403, 'Seul le propriétaire peut modifier ce calendrier.');
        }
        return $c;
    }

    public function store(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'calendars.create')['user'];
        $b = $req->json();
        $b += ['timezone' => $u->timezone];
        $id = $this->calendars->insert($u->id, self::validate($b, null));
        $this->changes?->record('calendar', $id, 'created', $u->id, $req->ip, $id);
        return Response::json(['data' => $this->present($this->calendars->find($id), $u)], 201);
    }

    public function update(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'calendars.create')['user'];
        $c = $this->manageable($u, $p);
        $b = $req->json();
        $version = null;
        if (array_key_exists('version', $b)) {
            $version = Rules::id($b['version']) ?? throw new HttpException(422, 'Données invalides.', ['version' => ['Version invalide.']]);
        }
        if (!$this->calendars->update((int) $c['id'], self::validate($b, $c), $version)) {
            throw new HttpException(409, 'Ce calendrier a été modifié entre-temps.', ['code' => 'version_conflict']);
        }
        $this->changes?->record('calendar', (int) $c['id'], 'updated', $u->id, $req->ip, (int) $c['id']);
        return Response::json(['data' => $this->present($this->calendars->find((int) $c['id']), $u)]);
    }

    /** Suppression logique : les rendez-vous du calendrier cessent d'être visibles. */
    public function destroy(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'calendars.create')['user'];
        $c = $this->manageable($u, $p);
        $this->calendars->softDelete((int) $c['id']);
        $this->changes?->record('calendar', (int) $c['id'], 'deleted', $u->id, $req->ip, (int) $c['id']);
        return Response::json(['ok' => true]);
    }

    /** @return array<string,mixed> calendrier dont l'utilisateur gère le partage */
    private function shareable(AuthUser $u, array $p): array
    {
        $id = Rules::id($p['id'] ?? null) ?? throw new HttpException(404, 'Calendrier introuvable.');
        $c = $this->calendars->find($id);
        if (!$c || !$this->perms->canAccessCalendar($u, $id, 'read')) {
            throw new HttpException(404, 'Calendrier introuvable.');
        }
        if (!$this->perms->canAccessCalendar($u, $id, 'share') && (int) $c['owner_id'] !== $u->id && !$u->can('calendars.manage_all')) {
            throw new HttpException(403, 'Vous ne pouvez pas gérer le partage de ce calendrier.');
        }
        return $c;
    }

    public function permissions(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $c = $this->shareable($u, $p);
        $rows = array_map(fn ($r) => ['user_id' => (int) $r['user_id'], 'name' => $r['name'], 'username' => $r['username'],
            'can_read' => (bool) $r['can_read'], 'can_create' => (bool) $r['can_create'], 'can_update' => (bool) $r['can_update'],
            'can_delete' => (bool) $r['can_delete'], 'can_share' => (bool) $r['can_share']], $this->calendars->permissions((int) $c['id']));
        return Response::json(['data' => $rows, 'meta' => ['owner' => $c['owner_name'], 'visibility' => $c['visibility']]]);
    }

    /** Remplace l'ensemble des partages : {permissions: [{user_id, can_read, can_create, can_update, can_delete, can_share}]}. */
    public function setPermissions(Request $req, array $p): Response
    {
        $u = $this->guard->requirePermission($req, 'appointments.read')['user'];
        $c = $this->shareable($u, $p);
        $in = $req->json()['permissions'] ?? null;
        if (!is_array($in) || count($in) > 200) {
            throw new HttpException(422, 'Données invalides.', ['permissions' => ['Liste de 200 partages maximum attendue.']]);
        }
        $grants = [];
        $e = [];
        foreach ($in as $i => $g) {
            $uid = is_array($g) ? Rules::id($g['user_id'] ?? null) : null;
            if ($uid === null) {
                $e['permissions'][] = "Ligne $i : utilisateur invalide.";
                continue;
            }
            if ($uid === (int) $c['owner_id']) {
                $e['permissions'][] = 'Le propriétaire a déjà tous les droits.';
                continue;
            }
            $flags = [];
            foreach (['can_read', 'can_create', 'can_update', 'can_delete', 'can_share'] as $f) {
                $flags[$f] = !empty($g[$f]) ? 1 : 0;
            }
            if (array_sum($flags) === 0) {
                continue; // aucun droit : le partage est retiré
            }
            $flags['can_read'] = 1; // écrire suppose lire
            $grants[$uid] = ['user_id' => $uid] + $flags;
        }
        $active = $this->calendars->activeUserIds(array_keys($grants));
        foreach (array_diff(array_keys($grants), $active) as $bad) {
            $e['permissions'][] = "Utilisateur $bad introuvable ou désactivé.";
        }
        Rules::throwIf($e);
        $this->calendars->replacePermissions((int) $c['id'], array_values($grants));
        $this->changes?->record('calendar', (int) $c['id'], 'updated', $u->id, $req->ip, (int) $c['id']);
        return $this->permissions($req, $p);
    }

    /** Recherche d'utilisateurs pour le partage (nom et identifiant uniquement). */
    public function lookupUsers(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'calendars.create')['user'];
        $q = $req->query['q'] ?? '';
        if (!is_string($q) || mb_strlen(trim($q)) < 2 || mb_strlen($q) > 100) {
            throw new HttpException(422, 'Saisissez au moins 2 caractères.');
        }
        return Response::json(['data' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'username' => $r['username']], $this->calendars->lookupUsers(trim($q), $u->id))]);
    }
}
