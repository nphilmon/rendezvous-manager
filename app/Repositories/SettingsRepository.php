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

    public function __construct(private PDO $pdo)
    {
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
