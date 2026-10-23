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
}
