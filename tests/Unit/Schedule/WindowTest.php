<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Schedule;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Watcher\Schedule\Window;

final class WindowTest extends TestCase
{
    public function testDefaultDurationIsTwentyFourHours(): void
    {
        $end = new DateTimeImmutable('2026-01-02T00:00:00Z');
        $window = Window::endingAt($end);

        $this->assertSame('2026-01-01T00:00:00+00:00', $window->start->format('c'));
        $this->assertEquals($end, $window->end);
    }

    public function testEndMinusDurationEqualsStart(): void
    {
        $end = new DateTimeImmutable('2026-01-02T12:30:00Z');

        $fromString = Window::endingAt($end, 'PT90M');
        $fromInterval = Window::endingAt($end, new DateInterval('PT90M'));

        $this->assertSame('2026-01-02T11:00:00+00:00', $fromString->start->format('c'));
        $this->assertTrue($fromString->equals($fromInterval));
    }

    public function testEqualityByStartAndEndIgnoresTimezone(): void
    {
        $a = new Window(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-02T00:00:00Z'));
        $b = new Window(
            new DateTimeImmutable('2026-01-01T02:00:00', new DateTimeZone('Europe/Kyiv')),
            new DateTimeImmutable('2026-01-02T02:00:00', new DateTimeZone('Europe/Kyiv')),
        );
        $c = new Window(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-02T00:00:01Z'));

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testStartMustBeStrictlyBeforeEnd(): void
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00Z');

        $this->expectException(InvalidArgumentException::class);
        new Window($t, $t);
    }

    public function testInvalidDurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Window::endingAt(new DateTimeImmutable('2026-01-01T00:00:00Z'), 'not-a-duration');
    }

    public function testZeroDurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Window::endingAt(new DateTimeImmutable('2026-01-01T00:00:00Z'), 'PT0S');
    }
}
