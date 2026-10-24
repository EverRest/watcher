<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Schedule\RunGate;

final class RunGateTest extends TestCase
{
    private function at(string $s, string $tz = 'UTC'): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone($tz));
    }

    public function testDueOnlyWithinTheHourAfterRunAt(): void
    {
        $g = new RunGate('06:00', new DateTimeZone('UTC'), 'PT24H');
        self::assertNull($g->decide($this->at('2026-03-10 05:59:59')));
        self::assertNotNull($g->decide($this->at('2026-03-10 06:00:00')));
        self::assertNotNull($g->decide($this->at('2026-03-10 06:59:59')));
        self::assertNull($g->decide($this->at('2026-03-10 07:00:00')));
    }

    public function testWindowEndIsTheSameForAnyTriggerInTheHour(): void
    {
        $g = new RunGate('06:00', new DateTimeZone('UTC'), 'PT24H');
        $a = $g->decide($this->at('2026-03-10 06:00:00'));
        $b = $g->decide($this->at('2026-03-10 06:59:59'));
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertTrue($a->equals($b));
        self::assertSame('2026-03-10T06:00:00+00:00', $a->end->format('c'));
        self::assertSame('2026-03-09T06:00:00+00:00', $a->start->format('c'));
    }

    public function testTimezoneAndWindowLength(): void
    {
        $g = new RunGate('06:00', new DateTimeZone('Europe/Kyiv'), new \DateInterval('PT12H'));
        $w = $g->decide($this->at('2026-01-15 06:30:00', 'Europe/Kyiv')); // UTC+2
        self::assertNotNull($w);
        self::assertSame('2026-01-15T04:00:00+00:00', $w->end->format('c'));
        self::assertSame('2026-01-14T16:00:00+00:00', $w->start->format('c'));
        // 06:30 UTC is 08:30 Kyiv: not due
        self::assertNull($g->decide($this->at('2026-01-15 06:30:00', 'UTC')));
        // 04:30 UTC is 06:30 Kyiv: due
        self::assertNotNull($g->decide($this->at('2026-01-15 04:30:00', 'UTC')));
    }

    public function testSpringForwardDay(): void
    {
        // Kyiv 2026-03-29: 03:00 -> 04:00 (UTC+2 -> +3). 06:00 local = 03:00 UTC.
        $g = new RunGate('06:00', new DateTimeZone('Europe/Kyiv'), 'PT24H');
        $w = $g->decide($this->at('2026-03-29 03:10:00', 'UTC'));
        self::assertNotNull($w);
        self::assertSame('2026-03-29T03:00:00+00:00', $w->end->format('c'));
        self::assertNull($g->decide($this->at('2026-03-29 02:59:00', 'UTC')));
    }

    public function testFallBackDay(): void
    {
        // Kyiv 2026-10-25: 04:00 -> 03:00 (UTC+3 -> +2). 06:00 local = 04:00 UTC.
        $g = new RunGate('06:00', new DateTimeZone('Europe/Kyiv'), 'PT24H');
        $w = $g->decide($this->at('2026-10-25 04:10:00', 'UTC'));
        self::assertNotNull($w);
        self::assertSame('2026-10-25T04:00:00+00:00', $w->end->format('c'));
        self::assertNull($g->decide($this->at('2026-10-25 03:59:00', 'UTC')));
        self::assertNull($g->decide($this->at('2026-10-25 05:00:00', 'UTC')));
    }

    public function testRunAtInsideSkippedHourStillResolves(): void
    {
        // 03:30 does not exist in Kyiv on 2026-03-29; PHP rolls it to 04:30 local (01:30 UTC).
        $g = new RunGate('03:30', new DateTimeZone('Europe/Kyiv'), 'PT24H');
        self::assertNotNull($g->decide($this->at('2026-03-29 01:45:00', 'UTC')));
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function dstChains(): iterable
    {
        yield 'Kyiv spring 06:00' => ['Europe/Kyiv', '06:00', '2026-03-26', 6];
        yield 'Kyiv spring 03:30 (nonexistent)' => ['Europe/Kyiv', '03:30', '2026-03-26', 6];
        yield 'Kyiv fall 06:00' => ['Europe/Kyiv', '06:00', '2026-10-22', 6];
        yield 'Kyiv fall 03:30 (ambiguous)' => ['Europe/Kyiv', '03:30', '2026-10-22', 6];
        yield 'New York spring 02:30 (nonexistent)' => ['America/New_York', '02:30', '2026-03-06', 6];
        yield 'New York spring 06:00' => ['America/New_York', '06:00', '2026-03-06', 6];
        yield 'New York fall 01:30 (ambiguous)' => ['America/New_York', '01:30', '2026-10-30', 6];
        yield 'New York fall 06:00' => ['America/New_York', '06:00', '2026-10-30', 6];
        yield 'no DST' => ['Asia/Tokyo', '06:00', '2026-03-26', 6];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dstChains')]
    public function testConsecutiveWindowsShareBoundariesAcrossDst(string $tz, string $runAt, string $firstDay, int $days): void
    {
        $zone = new DateTimeZone($tz);
        $g = new RunGate($runAt, $zone, 'PT24H');
        $prevEnd = null;
        for ($i = 0; $i < $days; ++$i) {
            $day = (new DateTimeImmutable($firstDay, $zone))->setDate(2026, (int) substr($firstDay, 5, 2), (int) substr($firstDay, 8, 2) + $i);
            // a trigger 10 minutes after the (resolved) run_at instant
            $trigger = $day->setTime((int) substr($runAt, 0, 2), (int) substr($runAt, 3, 2))->modify('+10 minutes');
            $w = $g->decide($trigger);
            self::assertNotNull($w, "due on {$day->format('Y-m-d')}");
            if ($prevEnd !== null) {
                self::assertSame($prevEnd->format('c'), $w->start->format('c'), "start == previous end on {$day->format('Y-m-d')}");
            }
            $prevEnd = $w->end;
        }
    }

    public function testStartIsTheSameWallClockTheDayBefore(): void
    {
        // Kyiv fall back: 06:00 on the 25th is 04:00Z; the 24th 06:00 (+3) is 03:00Z, a 25 h window.
        $g = new RunGate('06:00', new DateTimeZone('Europe/Kyiv'), 'PT24H');
        $w = $g->decide($this->at('2026-10-25 04:10:00', 'UTC'));
        self::assertNotNull($w);
        self::assertSame('2026-10-24T03:00:00+00:00', $w->start->format('c'));
        // spring forward: 23 h window
        $w = $g->decide($this->at('2026-03-29 03:10:00', 'UTC'));
        self::assertNotNull($w);
        self::assertSame('2026-03-28T04:00:00+00:00', $w->start->format('c'));
    }

    public function testMultiDayWindowsKeepWallClock(): void
    {
        $zone = new DateTimeZone('Europe/Kyiv');
        foreach (['PT48H', 'P2D'] as $d) {
            $w = (new RunGate('06:00', $zone, $d))->decide($this->at('2026-10-26 04:10:00', 'UTC'));
            self::assertNotNull($w);
            self::assertSame('2026-10-26T04:00:00+00:00', $w->end->format('c'));
            self::assertSame('2026-10-24T03:00:00+00:00', $w->start->format('c'));
        }
        $w = (new RunGate('06:00', $zone, 'P1D'))->decide($this->at('2026-10-26 04:10:00', 'UTC'));
        self::assertSame('2026-10-25T04:00:00+00:00', $w?->start->format('c'));
    }

    public function testSubDayWindowStaysEndMinusDuration(): void
    {
        $w = (new RunGate('06:00', new DateTimeZone('Europe/Kyiv'), 'PT6H'))->decide($this->at('2026-10-25 04:10:00', 'UTC'));
        self::assertSame('2026-10-24T22:00:00+00:00', $w?->start->format('c'));
    }

    public function testNonexistentRunAtResolvesToFirstInstantAfterTheGap(): void
    {
        // Kyiv 2026-03-29: 03:00 -> 04:00. 03:30 does not exist; the run is due from 04:00 local (01:00Z) for an hour.
        $g = new RunGate('03:30', new DateTimeZone('Europe/Kyiv'), 'PT24H');
        self::assertNull($g->decide($this->at('2026-03-29 00:59:00', 'UTC')));
        $w = $g->decide($this->at('2026-03-29 01:00:00', 'UTC'));
        self::assertNotNull($w);
        self::assertSame('2026-03-29T01:00:00+00:00', $w->end->format('c'));
        self::assertSame('2026-03-28T01:30:00+00:00', $w->start->format('c'));
        self::assertNotNull($g->decide($this->at('2026-03-29 01:59:00', 'UTC')));
        self::assertNull($g->decide($this->at('2026-03-29 02:00:00', 'UTC')));
    }

    public function testAmbiguousRunAtIsDueExactlyOncePerLocalDate(): void
    {
        // Kyiv 2026-10-25: 03:30 happens at 00:30Z (+3) and again at 01:30Z (+2). Only the first counts.
        $g = new RunGate('03:30', new DateTimeZone('Europe/Kyiv'), 'PT24H');
        $ends = [];
        $due = 0;
        for ($t = $this->at('2026-10-24 21:00:00', 'UTC'); $t < $this->at('2026-10-25 06:00:00', 'UTC'); $t = $t->modify('+1 minute')) {
            $w = $g->decide($t);
            if ($w !== null) {
                ++$due;
                $ends[$w->end->format('c')] = true;
            }
        }
        self::assertSame(['2026-10-25T00:30:00+00:00'], array_keys($ends));
        self::assertSame(60, $due);
        self::assertNull($g->decide($this->at('2026-10-25 01:30:00', 'UTC')));
        self::assertNull($g->decide($this->at('2026-10-25 01:45:00', 'UTC')));
    }

    public function testEndBackKeepsTheLocalWallClockAcrossClockChanges(): void
    {
        $g = new RunGate('06:00', new DateTimeZone('Europe/Kyiv'), 'PT24H');

        // autumn: 2026-10-25 03:00 clocks go back; 06:00 local is 04:00Z after, 03:00Z before
        self::assertSame('2026-10-19T03:00:00+00:00', $g->endBack($this->at('2026-10-26 06:00', 'Europe/Kyiv'), 7)->setTimezone(new DateTimeZone('UTC'))->format('c'));
        // spring: 2026-03-29 03:00 clocks go forward
        self::assertSame('2026-03-23T04:00:00+00:00', $g->endBack($this->at('2026-03-30 06:00', 'Europe/Kyiv'), 7)->setTimezone(new DateTimeZone('UTC'))->format('c'));
    }

    public function testEndBackIntoASpringForwardGapUsesTheFirstInstantAfterIt(): void
    {
        $g = new RunGate('03:30', new DateTimeZone('Europe/Kyiv'), 'PT24H');

        self::assertSame('2026-03-29T01:00:00+00:00', $g->endBack($this->at('2026-03-30 03:30', 'Europe/Kyiv'), 1)->setTimezone(new DateTimeZone('UTC'))->format('c'));
    }

    public function testEndBackSevenAndTwentyEightDays(): void
    {
        $g = new RunGate('06:00', new DateTimeZone('UTC'), 'PT24H');
        $end = $this->at('2026-10-28 06:00');

        self::assertSame('2026-10-21T06:00:00+00:00', $g->endBack($end, 7)->format('c'));
        self::assertSame('2026-09-30T06:00:00+00:00', $g->endBack($end, 28)->format('c'));
    }
}
