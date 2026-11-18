<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use DateTimeImmutable;
use DateTimeZone;

final class NotificationText
{
    /**
     * @param array<string,mixed> $c candidat de ReminderPlanner
     * @return array{0:string,1:string} [titre, corps] ; en mode « minimal », aucun détail du rendez-vous
     */
    public static function make(array $c, string $privacy): array
    {
        $tz = new DateTimeZone($c['user_tz']);
        $start = new DateTimeImmutable($c['start'], new DateTimeZone('UTC'));
        if ($c['all_day']) {
            $when = $start->format('d/m/Y') . ' (journée entière)';
        } else {
            $when = $start->setTimezone($tz)->format('d/m/Y \à H:i');
        }
        if ($privacy === 'minimal') {
            return ['Rappel de rendez-vous', 'Vous avez un rendez-vous le ' . $when . '.'];
        }
        $body = 'Le ' . $when . ($c['location'] ? ' · ' . $c['location'] : '');
        if ($c['offset_minutes'] > 0) {
            $body .= ' (rappel ' . self::offset($c['offset_minutes']) . ' avant)';
        }
        return [mb_substr('Rappel : ' . $c['title'], 0, 200), mb_substr($body, 0, 500)];
    }

    private static function offset(int $m): string
    {
        if ($m % 1440 === 0) {
            return ($m / 1440) . ' j';
        }
        if ($m % 60 === 0) {
            return ($m / 60) . ' h';
        }
        return $m . ' min';
    }
}
