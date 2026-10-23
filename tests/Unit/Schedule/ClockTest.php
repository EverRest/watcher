<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Schedule\SystemClock;
use Watcher\Tests\Fakes\FrozenClock;

final class ClockTest extends TestCase
{
    public function testSystemClockReturnsCurrentTime(): void
    {
        $before = new DateTimeImmutable('now');
        $now = (new SystemClock())->now();
        $after = new DateTimeImmutable('now');

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual($after, $now);
    }

    public function testFrozenClockStaysPutUntilSetOrAdvanced(): void
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $clock = new FrozenClock($t);

        $this->assertEquals($t, $clock->now());
        $this->assertEquals($t, $clock->now());

        $clock->advance('+5 minutes');
        $this->assertSame('2026-01-01T00:05:00+00:00', $clock->now()->format('c'));

        $clock->set($t);
        $this->assertEquals($t, $clock->now());
    }
}
