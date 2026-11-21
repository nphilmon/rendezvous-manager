<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Réglages par utilisateur (table settings, user_id > 0) avec valeurs par défaut. Les secrets ne passent jamais ici. */
final class SettingsRepository
{
    public const NOTIFY_DEFAULTS = [
        'enabled' => true,
        'channels' => ['inapp'],
        'privacy' => 'full',        // full | minimal
        'missed' => 'deliver',      // deliver : rattraper les rappels manqués (24 h) | skip : ne rien rattraper (5 min)
        'quiet_start' => null,      // « HH:MM » heure locale de l'utilisateur
        'quiet_end' => null,
        'default_reminder' => null, // minutes avant, appliqué aux nouveaux rendez-vous
    ];

    /** Préférences d'affichage : valeurs par défaut < réglages de l'organisation (user_id 0) < réglages de l'utilisateur. */
    public const UI_DEFAULTS = [
        'theme' => 'auto',          // auto | light | dark
        'view' => 'week',           // vue d'ouverture du calendrier : day | week | month | agenda
        'week_start' => 1,          // 1 = lundi, 0 = dimanche
        'start_hour' => 0,          // première heure de la grille
        'end_hour' => 24,           // dernière heure (exclue)
        'step' => 30,               // pas horaire en minutes : 5 | 10 | 15 | 30 | 60
        'weekend' => true,
        'hour12' => false,
        'language' => 'fr',         // langue des noms de jours et de mois : fr | en (les textes de l'interface restent en français)
        'date_format' => 'auto',    // auto (selon la langue) | dmy | mdy | ymd
        'default_duration' => 60,   // minutes, pour un nouveau rendez-vous
        'default_calendar' => null, // calendrier proposé par défaut (par utilisateur seulement)
        'hidden_calendars' => [],   // calendriers masqués dans la vue (par utilisateur seulement)
    ];
    /** Réglages de l'organisation (administrateur). */
    public const GLOBAL_DEFAULTS = ['org_name' => '', 'default_timezone' => 'Europe/Paris'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string,mixed> valeurs décodées des clés « $prefix.* » d'un utilisateur (0 = organisation) */
    private function stored(int $userId, string $prefix): array
    {
        $st = $this->pdo->prepare('SELECT setting_key, setting_value FROM settings WHERE user_id = ? AND setting_key LIKE ?');
        $st->execute([$userId, $prefix . '.%']);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $out[substr($k, strlen($prefix) + 1)] = json_decode((string) $v, true);
        }
        return $out;
    }

    /** @param array<string,mixed> $values clés déjà validées */
    private function store(int $userId, string $prefix, array $values): void
    {
        $st = $this->pdo->prepare('INSERT INTO settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($values as $k => $v) {
            $st->execute([$userId, $prefix . '.' . $k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
        }
    }

    /** @return array<string,mixed> réglages d'affichage effectifs de l'utilisateur */
    public function ui(int $userId): array
    {
        $org = array_intersect_key($this->stored(0, 'ui'), self::UI_DEFAULTS);
        unset($org['default_calendar'], $org['hidden_calendars']);
        return array_replace(self::UI_DEFAULTS, $org, array_intersect_key($this->stored($userId, 'ui'), self::UI_DEFAULTS));
    }

    /** Vrai si l'utilisateur a déjà enregistré au moins un réglage d'affichage sur le serveur. */
    public function hasStoredUi(int $userId): bool
    {
        return $this->stored($userId, 'ui') !== [];
    }

    /** @param array<string,mixed> $values clés déjà validées (userId 0 = réglages par défaut de l'organisation) */
    public function saveUi(int $userId, array $values): void
    {
        $this->store($userId, 'ui', $values);
    }

    /** @return array<string,mixed> réglages d'organisation avec valeurs par défaut */
    public function global(): array
    {
        return array_replace(self::GLOBAL_DEFAULTS, array_intersect_key($this->stored(0, 'global'), self::GLOBAL_DEFAULTS));
    }

    /** @return array<string,mixed> réglages d'affichage par défaut de l'organisation (sans ceux qui sont personnels) */
    public function orgUi(): array
    {
        $o = array_replace(self::UI_DEFAULTS, array_intersect_key($this->stored(0, 'ui'), self::UI_DEFAULTS));
        unset($o['default_calendar'], $o['hidden_calendars']);
        return $o;
    }

    /** @param array<string,mixed> $values clés déjà validées */
    public function saveGlobal(array $values): void
    {
        $this->store(0, 'global', $values);
    }

    /** @return array<string,mixed> réglages de notification (valeurs par défaut incluses) */
    public function notifications(int $userId): array
    {
        $st = $this->pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE user_id = ? AND setting_key LIKE 'notify.%'");
        $st->execute([$userId]);
        $out = self::NOTIFY_DEFAULTS;
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $key = substr($k, 7);
            if (array_key_exists($key, $out)) {
                $d = json_decode((string) $v, true);
                $out[$key] = $d;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $values clés déjà validées */
    public function saveNotifications(int $userId, array $values): void
    {
        $st = $this->pdo->prepare('INSERT INTO settings (user_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($values as $k => $v) {
            $st->execute([$userId, 'notify.' . $k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
        }
    }

    /** @param list<int> $userIds @return array<int,array<string,mixed>> */
    public function notificationsFor(array $userIds): array
    {
        $out = [];
        foreach (array_unique($userIds) as $id) {
            $out[$id] = $this->notifications($id);
        }
        return $out;
    }
}
