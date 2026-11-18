<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class StatsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Nombre de rendez-vous (lignes ; une série compte pour un) dans les calendriers donnés. @param list<int> $calendarIds */
    public function totalAppointments(array $calendarIds): int
    {
        if ($calendarIds === []) {
            return 0;
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM appointments WHERE deleted_at IS NULL AND calendar_id IN (' . implode(',', array_fill(0, count($calendarIds), '?')) . ')');
        $st->execute($calendarIds);
        return (int) $st->fetchColumn();
    }

    public function unreadNotifications(int $userId): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM notification_deliveries WHERE user_id = ? AND channel = 'inapp' AND status = 'sent' AND read_at IS NULL");
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }
}
