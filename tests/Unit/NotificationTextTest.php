<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Notifications\NotificationText;
use Tests\TestCase;

/** Date et heure des rappels envoyés par le serveur : format choisi par l'utilisateur (jj/mm/aaaa par défaut, 24 h). */
final class NotificationTextTest extends TestCase
{
    private function body(array $ui, bool $allDay = false): string
    {
        $c = ['user_tz' => 'Europe/Paris', 'start' => '2026-11-18 14:05:00', 'all_day' => $allDay, 'location' => null, 'offset_minutes' => 0, 'title' => 'RDV'];
        return NotificationText::make($c, 'full', $ui)[1];
    }

    public function testDefaultsKeepTheFormerFormat(): void
    {
        $this->assertSame('Le 18/11/2026 à 15:05', $this->body([]));
        $this->assertSame('Le 18/11/2026 à 15:05', $this->body(['date_format' => 'auto', 'hour12' => false]));
    }

    public function testDateFormatsAndTwelveHourClock(): void
    {
        $this->assertSame('Le 11/18/2026 à 15:05', $this->body(['date_format' => 'mdy']));
        $this->assertSame('Le 2026-11-18 à 15:05', $this->body(['date_format' => 'ymd']));
        $this->assertSame('Le 18/11/2026 à 3:05 PM', $this->body(['hour12' => true]));
        $this->assertSame('Le 2026-11-18 (journée entière)', $this->body(['date_format' => 'ymd'], true));
    }

    public function testUnknownFormatFallsBackSafely(): void
    {
        $this->assertSame('Le 18/11/2026 à 15:05', $this->body(['date_format' => '<script>']));
    }
}
