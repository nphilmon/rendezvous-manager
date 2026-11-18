<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Models\AuthUser;
use App\Repositories\AppointmentRepository;
use App\Validation\AppointmentValidator;
use App\Services\Recurrence\RRule;
use App\Services\Recurrence\RecurrenceService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Règles métier des rendez-vous : permissions, validation, conflits, verrou optimiste, synchronisation et audit. */
final class AppointmentService
{
    private const EDITABLE = ['calendar_id', 'category_id', 'contact_id', 'title', 'description', 'notes', 'start', 'end', 'all_day',
        'timezone', 'location', 'address', 'status', 'priority', 'color', 'reminder_minutes'];
    // « scope », « occurrence » et « recurrence » sont lus séparément (voir updateSeries)

    private PDO $pdo;

    public function __construct(
        private AppointmentRepository $repo,
        private PermissionService $perms,
        private AuditLogger $audit,
    ) {
        $this->pdo = $repo->pdo();
    }

    /** Pour une série : l'occurrence demandée (début d'origine ISO), sinon la première. @return array<string,mixed> */
    public function get(AuthUser $u, int $id, ?string $occurrence = null): array
    {
        $row = $this->readable($u, $id);
        if ($row['recurring']) {
            $orig = $this->originalParam($occurrence) ?? $row['start_utc'];
            $exc = $this->repo->exceptionsFor([$id])[$id] ?? [];
            $o = RecurrenceService::find($row, $exc, $orig);
            if ($o === null) {
                throw new HttpException(404, 'Occurrence introuvable.');
            }
            $row = AppointmentRepository::occurrenceRow($row, $o);
        }
        return $this->present($row, $u);
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function create(AuthUser $u, array $in, string $ip): array
    {
        $data = AppointmentValidator::validate(array_intersect_key($in, array_flip(self::EDITABLE)), $u->timezone);
        $this->requireCalendar($u, $data['calendar_id'], 'create');
        $this->assertRefs($u, $data);
        $rule = $this->ruleFromInput($in, $data);
        if ($rule === null) { // les séries ne participent pas à la détection de conflits
            $this->checkConflicts($data, null, !empty($in['ignore_conflicts']));
        }
        $id = $this->tx(function () use ($u, $data, $ip, $rule): int {
            $id = $this->repo->insert($data, $u->id);
            if ($rule !== null) {
                $this->saveSeries($id, $rule, $data);
            }
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
        if ($row['recurring']) {
            return $this->updateSeries($u, $row, $version, $in, $ip, $action);
        }
        $current = $this->asInput($row, $u);
        $data = AppointmentValidator::validate(array_replace($current, array_intersect_key($in, array_flip(self::EDITABLE))), $u->timezone);
        if ($data['calendar_id'] !== (int) $row['calendar_id']) {
            $this->requireCalendar($u, $data['calendar_id'], 'create');
        }
        $this->assertRefs($u, $data, $row);
        if ((int) $row['version'] !== $version) {
            $this->versionConflict($u, $id);
        }
        $rule = $this->ruleFromInput($in, $data);
        $moved = $rule === null && ($data['calendar_id'] !== (int) $row['calendar_id'] || $data['start_utc'] !== $row['start_utc'] || $data['end_utc'] !== $row['end_utc'] || (int) $row['all_day'] !== $data['all_day']);
        if ($moved) {
            $this->checkConflicts($data, $id, !empty($in['ignore_conflicts']));
        }
        $this->tx(function () use ($u, $id, $version, $data, $row, $in, $ip, $action, $rule): void {
            if (!$this->repo->update($id, $version, $data)) {
                $this->versionConflict($u, $id);
            }
            if ($rule !== null) {
                $this->saveSeries($id, $rule, $data);
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
        return $this->update($u, $id, ['version' => $in['version'] ?? null, 'status' => 'cancelled'] + array_intersect_key($in, array_flip(['scope', 'occurrence'])), $ip, 'cancel');
    }

    /** @param array<string,mixed> $in @return array<string,mixed> */
    public function complete(AuthUser $u, int $id, array $in, string $ip): array
    {
        return $this->update($u, $id, ['version' => $in['version'] ?? null, 'status' => 'completed'] + array_intersect_key($in, array_flip(['scope', 'occurrence'])), $ip, 'complete');
    }

    /** Déplacer / reporter : seuls les horaires changent. @param array<string,mixed> $in @return array<string,mixed> */
    public function reschedule(AuthUser $u, int $id, array $in, string $ip): array
    {
        $only = array_intersect_key($in, array_flip(['version', 'start', 'end', 'ignore_conflicts', 'scope', 'occurrence']));
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
        if ($row['recurring'] && ($in['scope'] ?? null) !== 'all') {
            $this->deleteInSeries($u, $row, $version, $in, $ip);
            return;
        }
        if ($row['recurring'] && ($in['scope'] ?? null) === null) {
            throw new HttpException(422, 'Données invalides.', ['scope' => ['Portée requise pour une série.']]);
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
    public function duplicate(AuthUser $u, int $id, string $ip, ?string $occurrence = null): array
    {
        $row = $this->readable($u, $id);
        $this->requireCalendar($u, (int) $row['calendar_id'], 'create', true);
        if ($row['recurring']) { // la copie d'une occurrence est un rendez-vous simple
            $exc = $this->repo->exceptionsFor([$id])[$id] ?? [];
            $o = RecurrenceService::find($row, $exc, $this->originalParam($occurrence) ?? $row['start_utc']);
            if ($o === null) {
                throw new HttpException(404, 'Occurrence introuvable.');
            }
            $row = AppointmentRepository::occurrenceRow($row, $o);
        }
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

    // ---- séries récurrentes ---------------------------------------------------------------------------------

    /** @param array<string,mixed> $in @param array<string,mixed> $data @return array<string,mixed>|null règle, null si pas de récurrence */
    private function ruleFromInput(array $in, array $data): ?array
    {
        if (empty($in['recurrence'])) {
            return null;
        }
        if (!is_array($in['recurrence'])) {
            throw new HttpException(422, 'Données invalides.', ['recurrence' => ['Objet attendu.']]);
        }
        return RRule::fromInput($in['recurrence'], new DateTimeZone($data['all_day'] === 1 ? 'UTC' : $data['timezone']));
    }

    /** @param array<string,mixed> $rule @param array<string,mixed> $data */
    private function saveSeries(int $id, array $rule, array $data): void
    {
        $allDay = $data['all_day'] === 1;
        $tz = $allDay ? 'UTC' : $data['timezone'];
        $local = (new DateTimeImmutable($data['start_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i:s');
        $this->repo->saveRecurrence($id, $rule, $local, $tz);
    }

    /** « 2026-11-18T09:00:00Z » → « 2026-11-18 09:00:00 » ; null si absent. */
    private function originalParam(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(\.\d+)?Z$/', $iso, $m)) {
            throw new HttpException(422, 'Données invalides.', ['occurrence' => ['Début d\'occurrence ISO 8601 UTC (…Z) attendu.']]);
        }
        return $m[1] . ' ' . $m[2];
    }

    /** @param array<string,mixed> $in @return array{0:string,1:string,2:array<string,mixed>,3:array<string,array<string,mixed>>} [portée, début d'origine, occurrence, exceptions] */
    private function seriesTarget(array $row, array $in): array
    {
        $scope = $in['scope'] ?? null;
        if (!in_array($scope, ['single', 'following', 'all'], true)) {
            throw new HttpException(422, 'Données invalides.', ['scope' => ['Portée requise pour une série : single, following ou all.']]);
        }
        $orig = $this->originalParam(isset($in['occurrence']) && is_string($in['occurrence']) ? $in['occurrence'] : null);
        if ($orig === null) {
            if ($scope !== 'all') {
                throw new HttpException(422, 'Données invalides.', ['occurrence' => ['Occurrence requise pour cette portée.']]);
            }
            $orig = $row['start_utc'];
        }
        $exc = $this->repo->exceptionsFor([(int) $row['id']])[(int) $row['id']] ?? [];
        $occ = RecurrenceService::find($row, $exc, $orig);
        if ($occ === null) {
            throw new HttpException(404, 'Occurrence introuvable.');
        }
        return [$scope, $orig, $occ, $exc];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $in @return array<string,mixed> */
    private function updateSeries(AuthUser $u, array $row, int $version, array $in, string $ip, string $action): array
    {
        $id = (int) $row['id'];
        [$scope, $orig, $occ] = $this->seriesTarget($row, $in);
        if ((int) $row['version'] !== $version) {
            $this->versionConflict($u, $id);
        }
        $rec = $this->repo->recurrence($id);
        $current = $this->asInput(AppointmentRepository::occurrenceRow($row, $occ), $u);
        $data = AppointmentValidator::validate(array_replace($current, array_intersect_key($in, array_flip(self::EDITABLE))), $u->timezone);
        $first = $orig === $row['start_utc'];
        if ($scope === 'following' && $first) {
            $scope = 'all'; // « cette occurrence et les suivantes » depuis la première = toute la série
        }

        if ($scope === 'single') {
            return $this->updateSingle($u, $row, $version, $occ, $data, $ip, $action);
        }
        $this->requireCalendarMove($u, $row, $data);
        $this->assertRefs($u, $data, $row);
        $oldRule = RRule::parse($rec['rrule']);

        if ($scope === 'following') {
            $newRule = array_key_exists('recurrence', $in) && $in['recurrence'] !== null
                ? RRule::fromInput((array) $in['recurrence'], new DateTimeZone($data['all_day'] === 1 ? 'UTC' : $data['timezone']))
                : $oldRule;
            if (!array_key_exists('recurrence', $in) || $in['recurrence'] === null) {
                if ($oldRule['count'] !== null) {
                    $newRule['count'] = max(1, $oldRule['count'] - RecurrenceService::countBefore($row, $orig));
                }
            }
            $truncated = array_replace($oldRule, ['until' => gmdate('Y-m-d H:i:s', strtotime($orig . ' UTC') - 1), 'count' => null]);
            $newId = $this->tx(function () use ($u, $id, $version, $orig, $rec, $truncated, $newRule, $data, $row, $ip): int {
                if (!$this->repo->touch($id, $version)) {
                    $this->versionConflict($u, $id);
                }
                $this->repo->saveRecurrence($id, $truncated, $rec['dtstart_local'], $rec['timezone']);
                $this->repo->deleteExceptionsFrom($id, $orig);
                $new = $this->repo->insert($data, $u->id);
                $this->saveSeries($new, $newRule, $data);
                $this->repo->setReminder($new, $u->id, $data['reminder_minutes']);
                $this->repo->recordChange((int) $row['calendar_id'], $id, 'updated', $u->id);
                $this->repo->recordChange($data['calendar_id'], $new, 'created', $u->id);
                $this->audit->log('appointment.split', $u->id, 'appointment', $new, $ip, ['source' => $id, 'from' => $orig]);
                return $new;
            });
            return $this->get($u, $newId);
        }

        // toute la série : seuls l'heure du jour et la durée changent, sauf depuis la première occurrence (date modifiable)
        $newRule = array_key_exists('recurrence', $in) ? ($in['recurrence'] === null ? null : RRule::fromInput((array) $in['recurrence'], new DateTimeZone($data['all_day'] === 1 ? 'UTC' : $data['timezone']))) : $oldRule;
        $oldTz = $rec['timezone'];
        $newTz = $data['all_day'] === 1 ? 'UTC' : $data['timezone'];
        $dStart = new DateTimeImmutable($data['start_utc'], new DateTimeZone('UTC'));
        $span = strtotime($data['end_utc'] . ' UTC') - strtotime($data['start_utc'] . ' UTC');
        if ($data['all_day'] === 1) {
            $startUtc = $first ? $dStart : new DateTimeImmutable($row['start_utc'], new DateTimeZone('UTC'));
        } else {
            $localNew = $dStart->setTimezone(new DateTimeZone($newTz));
            $masterDay = $first ? $localNew->format('Y-m-d') : (new DateTimeImmutable($rec['dtstart_local'], new DateTimeZone($oldTz)))->format('Y-m-d');
            $startUtc = (new DateTimeImmutable($masterDay . ' ' . $localNew->format('H:i:s'), new DateTimeZone($newTz)))->setTimezone(new DateTimeZone('UTC'));
        }
        $data['start_utc'] = $startUtc->format('Y-m-d H:i:s');
        $data['end_utc'] = $startUtc->modify("+$span seconds")->format('Y-m-d H:i:s');
        $changed = $newRule === null || RRule::format($newRule) !== RRule::format($oldRule) || $data['start_utc'] !== $row['start_utc']
            || $span !== (int) (strtotime($row['end_utc'] . ' UTC') - strtotime($row['start_utc'] . ' UTC')) || $newTz !== $oldTz
            || (int) $row['all_day'] !== $data['all_day'];
        $this->tx(function () use ($u, $id, $version, $data, $row, $in, $ip, $action, $newRule, $changed): void {
            if (!$this->repo->update($id, $version, $data)) {
                $this->versionConflict($u, $id);
            }
            if ($newRule === null) {
                $this->repo->deleteRecurrence($id);
            } else {
                $this->saveSeries($id, $newRule, $data);
                if ($changed) {
                    $this->repo->deleteExceptionsFrom($id, '0000-01-01 00:00:00'); // les exceptions n'ont plus de sens
                }
            }
            if (array_key_exists('reminder_minutes', $in)) {
                $this->repo->setReminder($id, $u->id, $data['reminder_minutes']);
            }
            $this->repo->recordChange($data['calendar_id'], $id, 'updated', $u->id);
            if ($data['calendar_id'] !== (int) $row['calendar_id']) {
                $this->repo->recordChange((int) $row['calendar_id'], $id, 'deleted', $u->id);
            }
            $this->audit->log('appointment.' . $action . '_series', $u->id, 'appointment', $id, $ip, ['scope' => 'all']);
        });
        return $this->get($u, $id);
    }

    /** Une seule occurrence : exception « modified » (nouvel horaire et champs modifiés). @param array<string,mixed> $occ @param array<string,mixed> $data @param array<string,mixed> $row */
    private function updateSingle(AuthUser $u, array $row, int $version, array $occ, array $data, string $ip, string $action): array
    {
        $id = (int) $row['id'];
        $e = [];
        if ($data['calendar_id'] !== (int) $row['calendar_id']) {
            $e['calendar_id'][] = 'Non modifiable pour une seule occurrence.';
        }
        if ($data['category_id'] !== ($row['category_id'] === null ? null : (int) $row['category_id'])) {
            $e['category_id'][] = 'Non modifiable pour une seule occurrence.';
        }
        if ($data['contact_id'] !== ($row['contact_id'] === null ? null : (int) $row['contact_id'])) {
            $e['contact_id'][] = 'Non modifiable pour une seule occurrence.';
        }
        if ($data['all_day'] !== (int) $row['all_day']) {
            $e['all_day'][] = 'Non modifiable pour une seule occurrence.';
        }
        if ($e) {
            throw new HttpException(422, 'Données invalides.', $e);
        }
        $overrides = [];
        foreach (RecurrenceService::OVERRIDABLE as $f) {
            if ($data[$f] !== $row[$f]) {
                $overrides[$f] = $data[$f];
            }
        }
        $orig = $occ['original'];
        $span = strtotime($row['end_utc'] . ' UTC') - strtotime($row['start_utc'] . ' UTC');
        $unchangedTime = $data['start_utc'] === $orig && strtotime($data['end_utc'] . ' UTC') - strtotime($data['start_utc'] . ' UTC') === $span;
        $this->tx(function () use ($u, $id, $version, $row, $orig, $data, $overrides, $unchangedTime, $ip, $action): void {
            if (!$this->repo->touch($id, $version)) {
                $this->versionConflict($u, $id);
            }
            if ($unchangedTime && !$overrides) {
                $this->repo->deleteException($id, $orig);
            } else {
                $this->repo->upsertException($id, $orig, 'modified', $data['start_utc'], $data['end_utc'], $overrides ?: null);
            }
            $this->repo->recordChange((int) $row['calendar_id'], $id, 'updated', $u->id);
            $this->audit->log('appointment.' . $action . '_occurrence', $u->id, 'appointment', $id, $ip, ['occurrence' => $orig]);
        });
        return $this->get($u, $id, str_replace(' ', 'T', $orig) . 'Z');
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $data */
    private function requireCalendarMove(AuthUser $u, array $row, array $data): void
    {
        if ($data['calendar_id'] !== (int) $row['calendar_id']) {
            $this->requireCalendar($u, $data['calendar_id'], 'create');
        }
    }

    /** Suppression d'une occurrence ou de « cette occurrence et les suivantes ». @param array<string,mixed> $row @param array<string,mixed> $in */
    private function deleteInSeries(AuthUser $u, array $row, int $version, array $in, string $ip): void
    {
        $id = (int) $row['id'];
        [$scope, $orig] = $this->seriesTarget($row, $in);
        if ($scope === 'following' && $orig === $row['start_utc']) {
            $scope = 'all';
        }
        $rec = $this->repo->recurrence($id);
        $this->tx(function () use ($u, $id, $version, $row, $scope, $orig, $rec, $ip): void {
            if ($scope === 'all') {
                if (!$this->repo->softDelete($id, $version)) {
                    $this->versionConflict($u, $id);
                }
            } elseif (!$this->repo->touch($id, $version)) {
                $this->versionConflict($u, $id);
            } elseif ($scope === 'single') {
                $this->repo->upsertException($id, $orig, 'cancelled', null, null, null);
            } else {
                $rule = array_replace(RRule::parse($rec['rrule']), ['until' => gmdate('Y-m-d H:i:s', strtotime($orig . ' UTC') - 1), 'count' => null]);
                $this->repo->saveRecurrence($id, $rule, $rec['dtstart_local'], $rec['timezone']);
                $this->repo->deleteExceptionsFrom($id, $orig);
            }
            $this->repo->recordChange((int) $row['calendar_id'], $id, $scope === 'all' ? 'deleted' : 'updated', $u->id);
            $this->audit->log('appointment.delete_' . $scope, $u->id, 'appointment', $id, $ip, ['occurrence' => $orig]);
        });
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
