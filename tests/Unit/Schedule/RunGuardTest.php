<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Schedule\RunGuard;
use Watcher\Tests\Fakes\FrozenClock;

final class RunGuardTest extends TestCase
{
    public function testNoReasonWhileWithinDeadlineAndMemory(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00Z'));
        $g = new RunGuard($clock, 60, static fn (): array => [10, 100]);
        $clock->advance('+59 seconds');
        $this->assertNull($g->reason());
        $this->assertSame(1, $g->remainingSeconds());
    }

    public function testDeadlineExpiryIsStickyAndNamed(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00Z'));
        $g = new RunGuard($clock, 60, static fn (): array => [10, 100]);
        $clock->advance('+61 seconds');
        $this->assertSame('deadline', $g->reason());
        $clock->set(new DateTimeImmutable('2026-10-24T06:00:00Z'));
        $this->assertSame('deadline', $g->reason(), 'once expired it stays expired');
    }

    public function testMemoryAtEightyPercentOfTheLimitStops(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00Z'));
        $used = 79;
        $g = new RunGuard($clock, 600, static function () use (&$used): array {
            return [$used, 100];
        });
        $this->assertNull($g->reason());
        $used = 80;
        $this->assertSame('memory', $g->reason());
    }

    public function testUnlimitedMemoryNeverStops(): void
    {
        $g = new RunGuard(new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00Z')), null, static fn (): array => [PHP_INT_MAX, -1]);
        $this->assertNull($g->reason());
        $this->assertNull($g->remainingSeconds());
    }

    public function testLimitParsing(): void
    {
        $this->assertSame(128 * 1024 * 1024, RunGuard::bytes('128M'));
        $this->assertSame(1024 * 1024 * 1024, RunGuard::bytes('1G'));
        $this->assertSame(2048, RunGuard::bytes('2k'));
        $this->assertSame(-1, RunGuard::bytes('-1'));
    }
}
