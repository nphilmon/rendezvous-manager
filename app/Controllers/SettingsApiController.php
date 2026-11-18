<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\Authenticator;
use App\Repositories\SettingsRepository;
use App\Validation\AppointmentValidator;
use App\Validation\Rules;

/** Réglages de l'utilisateur connecté (groupe « notifications » pour l'instant ; les autres groupes arrivent en phase 21). */
final class SettingsApiController
{
    /** Canaux réellement disponibles sur ce serveur (push et e-mail : phase 13). */
    public const CHANNELS_AVAILABLE = ['inapp'];

    public function __construct(private Authenticator $guard, private SettingsRepository $settings)
    {
    }

    public function show(Request $req): Response
    {
        $u = $this->guard->require($req)['user'];
        return Response::json(['notifications' => $this->settings->notifications($u->id), 'meta' => ['channels_available' => self::CHANNELS_AVAILABLE, 'timezone' => $u->timezone]]);
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
            if (!is_array($in['channels']) || array_diff($in['channels'], self::CHANNELS_AVAILABLE) || !in_array('inapp', $in['channels'], true)) {
                $e['channels'][] = 'Canaux disponibles : ' . implode(', ', self::CHANNELS_AVAILABLE) . ' (« inapp » obligatoire).';
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
        $this->settings->saveNotifications($u->id, $out);
        return $this->show($req);
    }
}
