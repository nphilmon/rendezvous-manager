<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Services\Recurrence\RRule;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

final class RRuleTest extends TestCase
{
    /** @return list<string> occurrences locales « Y-m-d H:i » */
    private function occ(string $rule, string $start, int $n, string $tz = 'Europe/Paris'): array
    {
        $out = [];
        foreach (RRule::iterate(RRule::parse($rule), new DateTimeImmutable($start, new DateTimeZone($tz))) as $dt) {
            $out[] = $dt->format('Y-m-d H:i');
            if (count($out) >= $n) {
                break;
            }
        }
        return $out;
    }

    private function dates(array $o): array
    {
        return array_map(fn ($s) => substr($s, 0, 10), $o);
    }

    public function testDailyCountAndInterval(): void
    {
        $this->assertSame(['2026-11-18', '2026-11-19', '2026-11-20'], $this->dates($this->occ('FREQ=DAILY;COUNT=3', '2026-11-18 09:00', 10)));
        $this->assertSame(['2026-11-18', '2026-11-20', '2026-11-22'], $this->dates($this->occ('FREQ=DAILY;INTERVAL=2', '2026-11-18 09:00', 3)));
    }

    public function testWeeklyByDayAndInterval(): void
    {
        $this->assertSame(['2026-11-18', '2026-11-20', '2026-11-23', '2026-11-25', '2026-11-27'], $this->dates($this->occ('FREQ=WEEKLY;BYDAY=MO,WE,FR', '2026-11-18 09:00', 5)));
        $this->assertSame(['2026-11-16', '2026-11-19', '2026-11-30', '2026-12-03'], $this->dates($this->occ('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,TH', '2026-11-16 09:00', 4)));
        $this->assertSame(['2026-11-18', '2026-11-25', '2026-12-02'], $this->dates($this->occ('FREQ=WEEKLY', '2026-11-18 09:00', 3)), 'jour de DTSTART par défaut');
    }

    public function testWeeklyWkstChangesBiWeeklyGrouping(): void
    {
        // dimanche 22/11 : avec WKST=MO il appartient à la semaine du 16 ; avec WKST=SU à celle du 22
        $mo = $this->dates($this->occ('FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,MO', '2026-11-22 09:00', 4));
        $su = $this->dates($this->occ('FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,MO;WKST=SU', '2026-11-22 09:00', 4));
        $this->assertSame(['2026-11-22', '2026-11-30', '2026-12-06', '2026-12-14'], $mo);
        $this->assertSame(['2026-11-22', '2026-11-23', '2026-12-06', '2026-12-07'], $su);
    }

    public function testMonthlySkipsShortMonthsAndNegativeDay(): void
    {
        $this->assertSame(['2027-01-31', '2027-03-31', '2027-05-31'], $this->dates($this->occ('FREQ=MONTHLY', '2027-01-31 09:00', 3)));
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $this->dates($this->occ('FREQ=MONTHLY;BYMONTHDAY=-1', '2027-01-31 09:00', 3)));
    }

    public function testMonthlyNthWeekday(): void
    {
        $this->assertSame(['2026-11-10', '2026-12-08', '2027-01-12'], $this->dates($this->occ('FREQ=MONTHLY;BYDAY=2TU', '2026-11-10 09:00', 3)));
        $this->assertSame(['2026-11-27', '2026-12-25', '2027-01-29'], $this->dates($this->occ('FREQ=MONTHLY;BYDAY=-1FR', '2026-11-27 09:00', 3)));
        $this->assertSame(['2026-11-02', '2026-11-09'], $this->dates($this->occ('FREQ=MONTHLY;BYDAY=MO', '2026-11-02 09:00', 2)), 'tous les lundis du mois');
    }

    public function testYearly(): void
    {
        $this->assertSame(['2028-02-29', '2032-02-29'], $this->dates($this->occ('FREQ=YEARLY', '2028-02-29 09:00', 2)), '29 février : années bissextiles seulement');
        $this->assertSame(['2026-03-15', '2026-06-15', '2027-03-15', '2027-06-15'], $this->dates($this->occ('FREQ=YEARLY;BYMONTH=3,6;BYMONTHDAY=15', '2026-03-15 09:00', 4)));
    }

    public function testUntilIsInclusiveAndCountExclusive(): void
    {
        $o = $this->occ('FREQ=DAILY;UNTIL=20261120T080000Z', '2026-11-18 09:00', 10); // 20/11 09:00 Paris = 08:00Z
        $this->assertSame(['2026-11-18', '2026-11-19', '2026-11-20'], $this->dates($o));
        $this->assertSame(2, count($this->occ('FREQ=DAILY;UNTIL=20261119', '2026-11-18 09:00', 10)), 'UNTIL en date : jusqu\'à la fin du jour (UTC)');
        $this->expectError('FREQ=DAILY;COUNT=3;UNTIL=20261231');
    }

    private function expectError(string $rule): void
    {
        try {
            RRule::parse($rule);
            $this->assertTrue(false, "règle acceptée : $rule");
        } catch (HttpException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function testInvalidRulesAreRejected(): void
    {
        foreach (['', 'FREQ=HOURLY', 'FREQ=DAILY;INTERVAL=0', 'FREQ=DAILY;COUNT=0', 'FREQ=DAILY;COUNT=999999', 'FREQ=WEEKLY;BYDAY=2MO', 'FREQ=DAILY;BYDAY=MO',
            'FREQ=DAILY;BYMONTHDAY=1', 'FREQ=MONTHLY;BYMONTH=1', 'FREQ=MONTHLY;BYDAY=6MO', 'FREQ=MONTHLY;BYMONTHDAY=32', 'FREQ=DAILY;BYSETPOS=1',
            'INTERVAL=2', 'FREQ=DAILY;FREQ=DAILY', 'FREQ=DAILY;UNTIL=pas-une-date', 'FREQ=DAILY;WKST=WE', 'FREQ', str_repeat('A', 600)] as $bad) {
            $this->expectError($bad);
        }
    }

    public function testFormatRoundTrip(): void
    {
        foreach (['FREQ=DAILY', 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;COUNT=10', 'FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20271231T225959Z', 'FREQ=YEARLY;BYMONTH=3,6;BYMONTHDAY=15', 'FREQ=WEEKLY;WKST=SU;BYDAY=SU'] as $s) {
            $this->assertSame(RRule::format(RRule::parse($s)), RRule::format(RRule::parse(RRule::format(RRule::parse($s)))), $s);
        }
        $this->assertSame('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;COUNT=10', RRule::format(RRule::parse('freq=weekly;byday=mo,we;interval=2;count=10')));
    }

    public function testDstKeepsWallClockTime(): void
    {
        $out = [];
        foreach (RRule::iterate(RRule::parse('FREQ=DAILY;COUNT=3'), new DateTimeImmutable('2026-03-28 09:00', new DateTimeZone('Europe/Paris'))) as $dt) {
            $out[] = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
        }
        $this->assertSame(['2026-03-28 08:00', '2026-03-29 07:00', '2026-03-30 07:00'], $out, 'même heure locale, instant UTC décalé après le passage à l\'heure d\'été');
    }

    public function testNonexistentLocalTimeShiftsForward(): void
    {
        $this->assertSame(['2026-03-28 02:30', '2026-03-29 03:30'], $this->occ('FREQ=DAILY;COUNT=2', '2026-03-28 02:30', 2), '02:30 n\'existe pas le 29/03 à Paris');
    }

    public function testJumpAheadAvoidsIteratingFromTheStart(): void
    {
        $tz = new DateTimeZone('Europe/Paris');
        $start = new DateTimeImmutable('2000-01-01 09:00', $tz);
        $nb = new DateTimeImmutable('2026-11-18 00:00', $tz);
        $t = microtime(true);
        $second = null;
        foreach (RRule::iterate(RRule::parse('FREQ=DAILY;INTERVAL=3'), $start, $nb) as $i => $dt) {
            if ($i === 1) {
                $second = $dt;
                break;
            }
        }
        $this->assertTrue($second !== null && $second >= $nb->modify('-8 days') && $second <= $nb->modify('+8 days'), 'saut vers la date visée : ' . $second?->format('c'));
        $days = (int) round(($second->getTimestamp() - $start->getTimestamp()) / 86400);
        $this->assertSame(0, $days % 3, 'respect de l\'intervalle après le saut');
        $this->assertTrue(microtime(true) - $t < 0.5);
        // la fréquence hebdomadaire et mensuelle sautent aussi
        foreach (['FREQ=WEEKLY;BYDAY=MO,FR', 'FREQ=MONTHLY;BYMONTHDAY=15', 'FREQ=YEARLY'] as $rule) {
            $first = null;
            foreach (RRule::iterate(RRule::parse($rule), $start, $nb) as $i => $dt) {
                if ($i >= 1) {
                    $first = $dt;
                    break;
                }
            }
            $this->assertTrue($first >= $start && $first < $nb->modify('+13 months'), "$rule : saut cohérent");
        }
    }

    public function testImpossibleRuleTerminates(): void
    {
        $t = microtime(true);
        $this->assertSame(1, count($this->occ('FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=30', '2026-02-10 09:00', 5)), 'seul DTSTART est produit');
        $this->assertTrue(microtime(true) - $t < 2.0);
    }

    public function testStructuredInputUsesInclusiveLocalEndDate(): void
    {
        $tz = new DateTimeZone('Europe/Paris');
        $rule = RRule::fromInput(['freq' => 'weekly', 'interval' => 2, 'byday' => ['MO', 'WE'], 'end' => ['type' => 'until', 'until' => '2026-12-24']], $tz);
        $this->assertSame('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;UNTIL=20261224T225959Z', RRule::format($rule));
        $back = RRule::toInput($rule, $tz);
        $this->assertSame('2026-12-24', $back['end']['until']);
        $this->assertSame(['MO', 'WE'], $back['byday']);
        $c = RRule::fromInput(['freq' => 'daily', 'end' => ['type' => 'count', 'count' => 5]], $tz);
        $this->assertSame(5, $c['count']);
        foreach ([['freq' => 'hourly'], ['freq' => 'daily', 'interval' => 'abc'], ['freq' => 'daily', 'end' => ['type' => 'until', 'until' => '2026-02-31']],
            ['freq' => 'daily', 'end' => ['type' => 'bof']], ['freq' => 'weekly', 'byday' => '1MO']] as $bad) {
            try {
                RRule::fromInput($bad, $tz);
                $this->assertTrue(false, json_encode($bad));
            } catch (HttpException) {
            }
        }
    }
}
