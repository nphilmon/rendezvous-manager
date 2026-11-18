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

/** Réglages de l'utilisateur connecté (groupe « notifications » pour l'instant ; les autres groupes arrivent en phase 21). */
final class SettingsApiController
{
    public function __construct(private Authenticator $guard, private SettingsRepository $settings, private ?\PDO $pdo = null)
    {
    }

    public function show(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        return Response::json(['notifications' => $this->settings->notifications($u->id), 'meta' => ['channels_available' => Channels::available(), 'timezone' => $u->timezone]]);
    }

    public function update(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        $in = $req->json()['notifications'] ?? null;
        if (!is_array($in)) {
            throw new HttpException(422, 'Données invalides.', ['notifications' => ['Objet attendu.']]);
        }
        $e = [];
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
        $merged = $out + $this->settings->notifications($u->id);
        if (($merged['quiet_start'] === null) !== ($merged['quiet_end'] === null)) {
            $e['quiet_start'][] = 'Renseignez le début et la fin des heures silencieuses (ou aucun des deux).';
        }
        Rules::throwIf($e);
        $before = $this->settings->notifications($u->id)['channels'];
        $this->settings->saveNotifications($u->id, $out);
        if (isset($out['channels']) && $out['channels'] !== $before && $this->pdo !== null) {
            (new ReminderChannelSync($this->pdo))->apply($u->id, $out['channels']);
        }
        return $this->show($req);
    }
}
