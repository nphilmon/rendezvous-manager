<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\SettingsRepository;
use App\Services\Notifications\Channels;
use App\Services\Notifications\ReminderChannelSync;
use App\Validation\AppointmentValidator;
use App\Validation\Rules;
use App\Validation\UiSettings;

/**
 * Réglages de l'utilisateur connecté (groupes « notifications », « ui » = affichage, « profile » = nom et fuseau) et, pour l'administrateur
 * (droit « settings.admin »), réglages de l'organisation. Tout est enregistré sur le serveur : on retrouve ses préférences sur chaque appareil.
 */
final class SettingsApiController
{
    public function __construct(private Authenticator $guard, private SettingsRepository $settings, private ?\PDO $pdo = null)
    {
    }

    public function show(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        return $this->payload($u);
    }

    private function payload(\App\Models\AuthUser $u): Response
    {
        $fresh = $this->pdo !== null ? (new \App\Repositories\UserRepository($this->pdo))->findAuthUser($u->id) ?? $u : $u;
        return Response::json([
            'notifications' => $this->settings->notifications($u->id),
            'ui' => $this->settings->ui($u->id),
            'profile' => ['first_name' => $fresh->firstName, 'last_name' => $fresh->lastName, 'timezone' => $fresh->timezone, 'email' => $fresh->email, 'username' => $fresh->username],
            'meta' => ['channels_available' => Channels::available(), 'timezone' => $fresh->timezone, 'ui_stored' => $this->settings->hasStoredUi($u->id)],
        ]);
    }

    /** PUT {notifications?, ui?, profile?} : au moins un groupe ; chaque groupe est validé en entier avant toute écriture. */
    public function update(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        $body = $req->json();
        $groups = array_intersect_key($body, ['notifications' => 1, 'ui' => 1, 'profile' => 1]);
        if ($groups === [] || array_diff(array_keys($body), ['notifications', 'ui', 'profile'])) {
            throw new HttpException(422, 'Données invalides.', ['settings' => ['Groupes attendus : notifications, ui, profile.']]);
        }
        foreach ($groups as $g => $v) {
            if (!is_array($v)) {
                throw new HttpException(422, 'Données invalides.', [$g => ['Objet attendu.']]);
            }
        }
        $e = [];
        $notif = isset($groups['notifications']) ? $this->validateNotifications($u->id, $groups['notifications'], $e) : null;
        $ui = null;
        if (isset($groups['ui'])) {
            $perms = new \App\Services\PermissionService($this->pdo);
            $ui = UiSettings::validate($groups['ui'], $this->settings->ui($u->id), static fn (int $id) => $perms->canAccessCalendar($u, $id, 'create'), $e);
        }
        $profile = isset($groups['profile']) ? $this->validateProfile($groups['profile'], $e) : null;
        Rules::throwIf($e);

        if ($notif !== null) {
            $before = $this->settings->notifications($u->id)['channels'];
            $this->settings->saveNotifications($u->id, $notif);
            if (isset($notif['channels']) && $notif['channels'] !== $before && $this->pdo !== null) {
                (new ReminderChannelSync($this->pdo))->apply($u->id, $notif['channels']);
            }
        }
        if ($ui !== null) {
            $this->settings->saveUi($u->id, $ui);
        }
        if ($profile !== null && $profile !== []) {
            $set = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($profile)));
            $this->pdo->prepare("UPDATE users SET $set WHERE id = ?")->execute([...array_values($profile), $u->id]);
            (new \App\Services\AuditLogger($this->pdo))->log('user.profile_update', $u->id, 'user', $u->id, $req->ip, ['fields' => array_keys($profile)]);
        }
        return $this->payload($u);
    }

    /** GET /api/v1/settings/global : réglages de l'organisation (droit « settings.admin »). */
    public function showGlobal(Request $req): Response
    {
        $this->guard->requirePermission($req, 'settings.admin');
        return Response::json(['global' => $this->settings->global(), 'ui' => $this->settings->orgUi()]);
    }

    /** PUT {global?: {org_name, default_timezone}, ui?: {…}} */
    public function updateGlobal(Request $req): Response
    {
        $u = $this->guard->requirePermission($req, 'settings.admin')['user'];
        $body = $req->json();
        if (array_intersect_key($body, ['global' => 1, 'ui' => 1]) === [] || array_diff(array_keys($body), ['global', 'ui'])) {
            throw new HttpException(422, 'Données invalides.', ['settings' => ['Groupes attendus : global, ui.']]);
        }
        $e = [];
        $g = [];
        foreach (['global', 'ui'] as $k) {
            if (isset($body[$k]) && !is_array($body[$k])) {
                $e[$k][] = 'Objet attendu.';
            }
        }
        $in = is_array($body['global'] ?? null) ? $body['global'] : [];
        foreach (array_diff(array_keys($in), array_keys(SettingsRepository::GLOBAL_DEFAULTS)) as $k) {
            $e[(string) $k][] = 'Réglage inconnu.';
        }
        if (array_key_exists('org_name', $in)) {
            $name = Rules::text($in, 'org_name', 80, false, $e);
            $g['org_name'] = $name ?? '';
        }
        if (array_key_exists('default_timezone', $in)) {
            is_string($in['default_timezone']) && in_array($in['default_timezone'], \DateTimeZone::listIdentifiers(), true) ? $g['default_timezone'] = $in['default_timezone'] : $e['default_timezone'][] = 'Fuseau horaire invalide.';
        }
        $ui = is_array($body['ui'] ?? null) ? UiSettings::validate($body['ui'], $this->settings->orgUi(), null, $e) : [];
        Rules::throwIf($e);
        $this->settings->saveGlobal($g);
        $this->settings->saveUi(0, $ui);
        (new \App\Services\AuditLogger($this->pdo))->log('settings.global_update', $u->id, null, null, $req->ip, ['fields' => array_merge(array_keys($g), array_map(static fn ($k) => "ui.$k", array_keys($ui)))]);
        return $this->showGlobal($req);
    }

    /** @param array<string,mixed> $in @param array<string,list<string>> $e @return array<string,mixed> */
    private function validateProfile(array $in, array &$e): array
    {
        $out = [];
        foreach (array_diff(array_keys($in), ['first_name', 'last_name', 'timezone']) as $k) {
            $e[(string) $k][] = 'Réglage inconnu (le nom d\'utilisateur et l\'e-mail se modifient par un administrateur).';
        }
        foreach (['first_name', 'last_name'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = (string) Rules::text($in, $k, 80, true, $e);
            }
        }
        if (array_key_exists('timezone', $in)) {
            is_string($in['timezone']) && in_array($in['timezone'], \DateTimeZone::listIdentifiers(), true) ? $out['timezone'] = $in['timezone'] : $e['timezone'][] = 'Fuseau horaire invalide.';
        }
        return $out;
    }

    /** @param array<string,mixed> $in @param array<string,list<string>> $e @return array<string,mixed> */
    private function validateNotifications(int $userId, array $in, array &$e): array
    {
        $out = [];
        $unknown = array_diff(array_keys($in), array_keys(SettingsRepository::NOTIFY_DEFAULTS));
        foreach ($unknown as $k) {
            $e[(string) $k][] = 'Réglage inconnu.';
        }
        if (array_key_exists('enabled', $in)) {
            is_bool($in['enabled']) ? $out['enabled'] = $in['enabled'] : $e['enabled'][] = 'Booléen attendu.';
        }
        if (array_key_exists('channels', $in)) {
            $avail = Channels::available();
            if (!is_array($in['channels']) || array_diff($in['channels'], $avail) || !in_array('inapp', $in['channels'], true)) {
                $e['channels'][] = 'Canaux disponibles : ' . implode(', ', $avail) . ' (« inapp » obligatoire).';
            } else {
                $out['channels'] = array_values(array_unique($in['channels']));
            }
        }
        $out += Rules::enumSetting($in, 'privacy', ['full', 'minimal'], $e) + Rules::enumSetting($in, 'missed', ['deliver', 'skip'], $e);
        foreach (['quiet_start', 'quiet_end'] as $k) {
            if (array_key_exists($k, $in)) {
                $v = $in[$k];
                if ($v === null || $v === '') {
                    $out[$k] = null;
                } elseif (is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                    $out[$k] = $v;
                } else {
                    $e[$k][] = 'Heure HH:MM attendue.';
                }
            }
        }
        if (array_key_exists('default_reminder', $in)) {
            $v = $in['default_reminder'];
            if ($v === null || $v === '') {
                $out['default_reminder'] = null;
            } elseif (is_int($v) && $v >= 0 && $v <= AppointmentValidator::MAX_REMINDER_MINUTES) {
                $out['default_reminder'] = $v;
            } else {
                $e['default_reminder'][] = 'Minutes entre 0 et ' . AppointmentValidator::MAX_REMINDER_MINUTES . ' attendues.';
            }
        }
        $merged = $out + $this->settings->notifications($userId);
        if (($merged['quiet_start'] === null) !== ($merged['quiet_end'] === null)) {
            $e['quiet_start'][] = 'Renseignez le début et la fin des heures silencieuses (ou aucun des deux).';
        }
        return $out;
    }
}
