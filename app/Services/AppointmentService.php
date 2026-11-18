<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Models\AuthUser;
use App\Repositories\AppointmentRepository;
use App\Validation\AppointmentValidator;
use PDO;

/** Règles métier des rendez-vous : permissions, validation, conflits, verrou optimiste, synchronisation et audit. */
final class AppointmentService
{
    private const EDITABLE = ['calendar_id', 'category_id', 'contact_id', 'title', 'description', 'notes', 'start', 'end', 'all_day',
        'timezone', 'location', 'address', 'status', 'priority', 'color', 'reminder_minutes'];

    private PDO $pdo;

    public function __construct(
        private AppointmentRepository $repo,
        private PermissionService $perms,
        private AuditLogger $audit,
    ) {
        $this->pdo = $repo->pdo();
    }

    /** @return array<string,mixed> */
    public function get(AuthUser $u, int $id): array
    {
        $row = $this->readable($u, $id);
        return $this->present($row, $u);
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function create(AuthUser $u, array $in, string $ip): array
    {
        $data = AppointmentValidator::validate(array_intersect_key($in, array_flip(self::EDITABLE)), $u->timezone);
        $this->requireCalendar($u, $data['calendar_id'], 'create');
        $this->assertRefs($u, $data);
        $this->checkConflicts($data, null, !empty($in['ignore_conflicts']));
        $id = $this->tx(function () use ($u, $data, $ip): int {
            $id = $this->repo->insert($data, $u->id);
            $this->repo->setReminder($id, $u->id, $data['reminder_minutes']);
            $this->repo->recordChange($data['calendar_id'], $id, 'created', $u->id);
            $this->audit->log('appointment.create', $u->id, 'appointment', $id, $ip, ['calendar_id' => $data['calendar_id']]);
            return $id;
        });
        return $this->present($this->repo->find($id), $u);
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function update(AuthUser $u, int $id, array $in, string $ip, string $action = 'update'): array
    {
        $row = $this->readable($u, $id);
        $version = $this->version($in);
        $this->requireCalendar($u, (int) $row['calendar_id'], 'update', true);
        $current = $this->asInput($row, $u);
        $data = AppointmentValidator::validate(array_replace($current, array_intersect_key($in, array_flip(self::EDITABLE))), $u->timezone);
        if ($data['calendar_id'] !== (int) $row['calendar_id']) {
            $this->requireCalendar($u, $data['calendar_id'], 'create');
        }
        $this->assertRefs($u, $data, $row);
        if ((int) $row['version'] !== $version) {
            $this->versionConflict($u, $id);
        }
        $moved = $data['calendar_id'] !== (int) $row['calendar_id'] || $data['start_utc'] !== $row['start_utc'] || $data['end_utc'] !== $row['end_utc'] || (int) $row['all_day'] !== $data['all_day'];
        if ($moved) {
            $this->checkConflicts($data, $id, !empty($in['ignore_conflicts']));
        }
        $this->tx(function () use ($u, $id, $version, $data, $row, $in, $ip, $action): void {
            if (!$this->repo->update($id, $version, $data)) {
                $this->versionConflict($u, $id);
            }
            if (array_key_exists('reminder_minutes', $in)) {
                $this->repo->setReminder($id, $u->id, $data['reminder_minutes']);
            }
            $this->repo->recordChange($data['calendar_id'], $id, 'updated', $u->id);
            if ($data['calendar_id'] !== (int) $row['calendar_id']) {
                $this->repo->recordChange((int) $row['calendar_id'], $id, 'deleted', $u->id); // disparaît de l'ancien calendrier
            }
            $this->audit->log('appointment.' . $action, $u->id, 'appointment', $id, $ip, ['calendar_id' => $data['calendar_id']]);
        });
        return $this->present($this->repo->find($id), $u);
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function cancel(AuthUser $u, int $id, array $in, string $ip): array
    {
        return $this->update($u, $id, ['version' => $in['version'] ?? null, 'status' => 'cancelled'], $ip, 'cancel');
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function complete(AuthUser $u, int $id, array $in, string $ip): array
    {
        return $this->update($u, $id, ['version' => $in['version'] ?? null, 'status' => 'completed'], $ip, 'complete');
    }

    /** Déplacer / reporter : seuls les horaires changent. @param array<string,mixed> $in @return array<string,mixed> */
    public function reschedule(AuthUser $u, int $id, array $in, string $ip): array
    {
        $only = array_intersect_key($in, array_flip(['version', 'start', 'end', 'ignore_conflicts']));
        if (!isset($only['start'], $only['end'])) {
            throw new HttpException(422, 'Données invalides.', ['start' => ['Champ requis.'], 'end' => ['Champ requis.']]);
        }
        return $this->update($u, $id, $only, $ip, 'reschedule');
    }

    /** @param array<string,mixed> $in */
    public function delete(AuthUser $u, int $id, array $in, string $ip): void
    {
        $row = $this->readable($u, $id);
        $this->requireCalendar($u, (int) $row['calendar_id'], 'delete', true);
        $version = $this->version($in);
        if ((int) $row['version'] !== $version) {
            $this->versionConflict($u, $id);
        }
        $this->tx(function () use ($u, $id, $version, $row, $ip): void {
            if (!$this->repo->softDelete($id, $version)) {
                $this->versionConflict($u, $id);
            }
            $this->repo->recordChange((int) $row['calendar_id'], $id, 'deleted', $u->id);
            $this->audit->log('appointment.delete', $u->id, 'appointment', $id, $ip, ['calendar_id' => (int) $row['calendar_id']]);
        });
    }

    /** @return array<string,mixed> */
    public function restore(AuthUser $u, int $id, string $ip): array
    {
        $row = $this->repo->find($id, true);
        if (!$row || $row['deleted_at'] === null || !$this->perms->canAccessCalendar($u, (int) $row['calendar_id'], 'delete')) {
            throw new HttpException(404, 'Rendez-vous introuvable.');
        }
        $this->tx(function () use ($u, $id, $row, $ip): void {
            $this->repo->restore($id);
            $this->repo->recordChange((int) $row['calendar_id'], $id, 'restored', $u->id);
            $this->audit->log('appointment.restore', $u->id, 'appointment', $id, $ip, ['calendar_id' => (int) $row['calendar_id']]);
        });
        return $this->present($this->repo->find($id), $u);
    }

    /** Copie à l'identique (même horaire : l'utilisateur la modifie ensuite) ; aucun contrôle de conflit. @return array<string,mixed> */
    public function duplicate(AuthUser $u, int $id, string $ip): array
    {
        $row = $this->readable($u, $id);
        $this->requireCalendar($u, (int) $row['calendar_id'], 'create', true);
        $in = $this->asInput($row, $u);
        $in['title'] = mb_substr($row['title'], 0, 190) . ' (copie)';
        $in['status'] = 'scheduled';
        $data = AppointmentValidator::validate($in, $u->timezone);
        $newId = $this->tx(function () use ($u, $data, $id, $ip): int {
            $new = $this->repo->insert($data, $u->id);
            $this->repo->setReminder($new, $u->id, $data['reminder_minutes']);
            $this->repo->recordChange($data['calendar_id'], $new, 'created', $u->id);
            $this->audit->log('appointment.duplicate', $u->id, 'appointment', $new, $ip, ['source' => $id]);
            return $new;
        });
        return $this->present($this->repo->find($newId), $u);
    }

    // ---- internes ------------------------------------------------------------------------------

    /** @return array<string,mixed> 404 si absent ou non lisible (n'indique pas l'existence). */
    private function readable(AuthUser $u, int $id): array
    {
        $row = $this->repo->find($id);
        if (!$row || !$this->perms->canAccessCalendar($u, (int) $row['calendar_id'], 'read')) {
            throw new HttpException(404, 'Rendez-vous introuvable.');
        }
        return $row;
    }

    /** @param bool $existing true : le rendez-vous est lisible, donc 403 (et non 404) si le droit manque */
    private function requireCalendar(AuthUser $u, int $calendarId, string $action, bool $existing = false): void
    {
        if (!$this->perms->canAccessCalendar($u, $calendarId, $action)) {
            throw $existing || $action === 'create'
                ? new HttpException(403, 'Permission insuffisante sur ce calendrier.')
                : new HttpException(404, 'Calendrier introuvable.');
        }
    }

    /** @param array<string,mixed> $in */
    private function version(array $in): int
    {
        $v = $in['version'] ?? null;
        if (!(is_int($v) && $v > 0) && !(is_string($v) && ctype_digit($v) && (int) $v > 0)) {
            throw new HttpException(422, 'Données invalides.', ['version' => ['La version du rendez-vous est requise.']]);
        }
        return (int) $v;
    }

    private function versionConflict(AuthUser $u, int $id): never
    {
        $fresh = $this->repo->find($id, true);
        throw new HttpException(409, 'Ce rendez-vous a été modifié ou supprimé entre-temps.', [
            'code' => 'version_conflict',
            'current' => $fresh && $fresh['deleted_at'] === null ? $this->present($fresh, $u) : null,
        ]);
    }

    /** @param array<string,mixed> $data */
    private function checkConflicts(array $data, ?int $excludeId, bool $ignore): void
    {
        if ($ignore || $data['all_day'] === 1 || $data['status'] === 'cancelled') {
            return;
        }
        $c = $this->repo->conflicts($data['calendar_id'], $data['start_utc'], $data['end_utc'], $excludeId);
        if ($c) {
            throw new HttpException(409, 'Conflit horaire avec un autre rendez-vous de ce calendrier.', [
                'code' => 'time_conflict',
                'conflicts' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'],
                    'start' => str_replace(' ', 'T', $r['start_utc']) . 'Z', 'end' => str_replace(' ', 'T', $r['end_utc']) . 'Z'], $c),
            ]);
        }
    }

    /** La catégorie et le contact doivent exister et être accessibles à l'utilisateur. @param array<string,mixed> $data @param array<string,mixed>|null $existing */
    private function assertRefs(AuthUser $u, array $data, ?array $existing = null): void
    {
        $e = [];
        if ($data['category_id'] !== null && ($existing === null || (int) ($existing['category_id'] ?? 0) !== $data['category_id'])) {
            $st = $this->pdo->prepare('SELECT 1 FROM categories WHERE id = ? AND deleted_at IS NULL');
            $st->execute([$data['category_id']]);
            if (!$st->fetchColumn()) {
                $e['category_id'][] = 'Catégorie introuvable.';
            }
        }
        if ($data['contact_id'] !== null && ($existing === null || (int) ($existing['contact_id'] ?? 0) !== $data['contact_id'])) {
            $st = $this->pdo->prepare('SELECT owner_id FROM contacts WHERE id = ? AND deleted_at IS NULL');
            $st->execute([$data['contact_id']]);
            $owner = $st->fetchColumn();
            if ($owner === false || ((int) $owner !== $u->id && !$u->can('calendars.manage_all'))) {
                $e['contact_id'][] = 'Contact introuvable.';
            }
        }
        if ($e) {
            throw new HttpException(422, 'Données invalides.', $e);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> représentation « entrée » d'un rendez-vous existant */
    private function asInput(array $row, AuthUser $u): array
    {
        $api = AppointmentRepository::toApi($row, true);
        return [
            'calendar_id' => $api['calendar_id'], 'category_id' => $api['category']['id'] ?? null, 'contact_id' => $api['contact_id'],
            'title' => $api['title'], 'description' => $api['description'], 'notes' => $api['notes'], 'start' => $api['start'], 'end' => $api['end'],
            'all_day' => $api['all_day'], 'timezone' => $api['timezone'], 'location' => $api['location'], 'address' => $api['address'],
            'status' => $api['status'], 'priority' => $api['priority'], 'color' => $api['color'],
            'reminder_minutes' => $this->repo->reminderFor((int) $row['id'], $u->id),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function present(array $row, AuthUser $u): array
    {
        $out = AppointmentRepository::toApi($row, true);
        $out['reminder_minutes'] = $this->repo->reminderFor((int) $row['id'], $u->id);
        $out['can'] = [
            'update' => $this->perms->canAccessCalendar($u, (int) $row['calendar_id'], 'update'),
            'delete' => $this->perms->canAccessCalendar($u, (int) $row['calendar_id'], 'delete'),
        ];
        return $out;
    }

    private function tx(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
