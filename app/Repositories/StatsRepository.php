<?php
declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class StatsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Compteurs réels, limités aux calendriers autorisés. Les bornes « aujourd'hui » et « semaine »
     * sont calculées dans le fuseau de l'utilisateur puis converties en UTC.
     * Les séries récurrentes ne sont pas encore dépliées (phase 9) : chaque série compte pour une ligne.
     * @param list<int> $calendarIds
     * @return array{total:int,today:int,week:int,upcoming:int}
     */
    public function counts(array $calendarIds, string $timezone, ?DateTimeImmutable $now = null): array
    {
        $zero = ['total' => 0, 'today' => 0, 'week' => 0, 'upcoming' => 0];
        if ($calendarIds === []) {
            return $zero;
        }
        $tz = new DateTimeZone($timezone);
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($tz);
        $dayStart = $now->setTime(0, 0);
        $weekStart = $dayStart->modify('-' . ((int) $dayStart->format('N') - 1) . ' days');
        $utc = static fn (DateTimeImmutable $d): string => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $in = implode(',', array_fill(0, count($calendarIds), '?'));
        $sql = "SELECT COUNT(*) total,
                  SUM(start_utc < ? AND end_utc >= ?) today,
                  SUM(start_utc < ? AND end_utc >= ?) week,
                  SUM(start_utc >= ? AND status NOT IN ('cancelled','completed')) upcoming
                FROM appointments WHERE deleted_at IS NULL AND calendar_id IN ($in)";
        $args = [$utc($dayStart->modify('+1 day')), $utc($dayStart), $utc($weekStart->modify('+7 days')), $utc($weekStart),
            $utc($now)];
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge($args, $calendarIds));
        $r = $st->fetch();
        return ['total' => (int) $r['total'], 'today' => (int) $r['today'], 'week' => (int) $r['week'], 'upcoming' => (int) $r['upcoming']];
    }

    public function unreadNotifications(int $userId): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM notification_deliveries WHERE user_id = ? AND channel = 'inapp' AND status = 'sent' AND read_at IS NULL");
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }
}
