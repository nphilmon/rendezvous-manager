<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use PDO;

/** Aligne les rappels existants d'un utilisateur sur ses canaux activés (après un changement de réglages). */
final class ReminderChannelSync
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param list<string> $channels */
    public function apply(int $userId, array $channels): void
    {
        $ins = $this->pdo->prepare('INSERT IGNORE INTO reminders (appointment_id, user_id, offset_minutes, channel)
            SELECT DISTINCT appointment_id, user_id, offset_minutes, ? FROM reminders WHERE user_id = ?');
        foreach ($channels as $ch) {
            $ins->execute([$ch, $userId]);
        }
        $in = implode(',', array_fill(0, count($channels), '?'));
        $this->pdo->prepare("UPDATE notification_deliveries SET status = 'cancelled', last_error = 'Canal désactivé par l\\'utilisateur'
            WHERE user_id = ? AND status = 'pending' AND channel NOT IN ($in)")->execute(array_merge([$userId], $channels));
        $this->pdo->prepare("DELETE FROM reminders WHERE user_id = ? AND channel NOT IN ($in)")->execute(array_merge([$userId], $channels));
    }
}
