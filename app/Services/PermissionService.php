<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuthUser;
use PDO;

/**
 * Autorisations côté serveur.
 * Accès à un calendrier = permission de rôle ET droit sur le calendrier :
 *  - le propriétaire et les détenteurs de calendars.manage_all ont tous les droits de calendrier ;
 *  - sinon les droits viennent de calendar_permissions ; visibility=organization donne la lecture à tout compte actif ;
 *  - les écritures exigent en plus la permission de rôle correspondante (un compte « Lecture seule » ne peut jamais écrire).
 */
final class PermissionService
{
    public const ACTIONS = ['read', 'create', 'update', 'delete', 'share'];
    private const ROLE_CAP = ['create' => 'appointments.write', 'update' => 'appointments.write',
        'delete' => 'appointments.delete', 'share' => 'calendars.create'];

    public function __construct(private PDO $pdo)
    {
    }

    public function canAccessCalendar(AuthUser $u, int $calendarId, string $action): bool
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT owner_id, visibility FROM calendars WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$calendarId]);
        $cal = $st->fetch();
        if (!$cal) {
            return false;
        }
        if ($action === 'read' && !$u->can('appointments.read')) {
            return false;
        }
        if (isset(self::ROLE_CAP[$action]) && !$u->can(self::ROLE_CAP[$action])) {
            return false;
        }
        if ((int) $cal['owner_id'] === $u->id || $u->can('calendars.manage_all')) {
            return true;
        }
        $st = $this->pdo->prepare('SELECT * FROM calendar_permissions WHERE calendar_id = ? AND user_id = ?');
        $st->execute([$calendarId, $u->id]);
        $p = $st->fetch();
        if ($p && (int) $p['can_' . $action] === 1) {
            return true;
        }
        return $action === 'read' && $cal['visibility'] === 'organization';
    }

    /** Empreinte de l'ensemble des calendriers lisibles : change quand un partage est accordé ou retiré. */
    public function accessToken(AuthUser $u): string
    {
        $ids = $this->readableCalendarIds($u);
        sort($ids);
        return substr(sha1(implode(',', $ids)), 0, 12);
    }

    /** @return list<int> identifiants des calendriers lisibles par l'utilisateur */
    public function readableCalendarIds(AuthUser $u): array
    {
        if (!$u->can('appointments.read')) {
            return [];
        }
        if ($u->can('calendars.manage_all')) {
            return array_map('intval', $this->pdo->query('SELECT id FROM calendars WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_COLUMN));
        }
        $st = $this->pdo->prepare(
            "SELECT c.id FROM calendars c
             LEFT JOIN calendar_permissions cp ON cp.calendar_id = c.id AND cp.user_id = :u
             WHERE c.deleted_at IS NULL AND (c.owner_id = :u2 OR cp.can_read = 1 OR c.visibility = 'organization')"
        );
        $st->execute([':u' => $u->id, ':u2' => $u->id]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}
