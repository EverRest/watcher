<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\State\RedisStateStore;
use Watcher\Tests\Fakes\FakeRedisServer;
use Watcher\Tests\Fakes\FrozenClock;

/** A reservation belongs to the day it was made on: settling or releasing it after midnight must not touch the new day's keys. */
final class SpendDayBoundaryTest extends TestCase
{
    private FrozenClock $clock;
    private RedisStateStore $store;

    private function spend(string $tz = 'UTC'): Spend
    {
        return new Spend($this->store, $this->clock, new DateTimeZone($tz), 'svc');
    }

    private function setUpAt(string $instant): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable($instant));
        $this->store = new RedisStateStore(new FakeRedisServer($this->clock), $this->clock);
    }

    /** @return array{usd: float, tokens: int} */
    private function totalOn(string $day, string $tz = 'UTC'): array
    {
        $at = $this->clock->now();
        $this->clock->set(new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone($tz)));
        $t = $this->spend($tz)->today();
        $this->clock->set($at);

        return $t;
    }

    public function testSettleAfterMidnightReleasesTheOldDayAndBooksTheNewOne(): void
    {
        $this->setUpAt('2026-10-23T23:59:58+00:00');
        $spend = $this->spend();
        self::assertTrue($spend->reserve(0.10, 10000, 1.0, null));

        $this->clock->set(new DateTimeImmutable('2026-10-24T00:00:02+00:00'));
        $spend->add(new Usage('m', 1000, 500), 0.015);

        self::assertEqualsWithDelta(0.0, $this->totalOn('2026-10-23')['usd'], 1e-9, 'the old day gets its reservation back');
        self::assertSame(0, $this->totalOn('2026-10-23')['tokens']);
        self::assertEqualsWithDelta(0.015, $this->totalOn('2026-10-24')['usd'], 1e-9, 'the new day gets the actual cost, no negative adjustment');
        self::assertSame(1500, $this->totalOn('2026-10-24')['tokens']);
    }

    public function testReleaseAfterMidnightTargetsTheReservationsDay(): void
    {
        $this->setUpAt('2026-10-23T23:59:58+00:00');
        $spend = $this->spend();
        self::assertTrue($spend->reserve(0.10, 10000, 1.0, null));

        $this->clock->set(new DateTimeImmutable('2026-10-24T00:00:02+00:00'));
        $spend->release();

        self::assertEqualsWithDelta(0.0, $this->totalOn('2026-10-23')['usd'], 1e-9);
        self::assertEqualsWithDelta(0.0, $this->totalOn('2026-10-24')['usd'], 1e-9, 'the new day is not driven negative');
    }

    public function testAnEarlierReservationIsReleasedOnTheDayItWasMadeWhenANewOneStarts(): void
    {
        $this->setUpAt('2026-10-23T23:59:58+00:00');
        $spend = $this->spend();
        self::assertTrue($spend->reserve(0.10, 10000, 1.0, null));
        $this->clock->set(new DateTimeImmutable('2026-10-24T00:00:02+00:00'));

        self::assertTrue($spend->reserve(0.20, 20000, 1.0, null));

        self::assertEqualsWithDelta(0.0, $this->totalOn('2026-10-23')['usd'], 1e-9);
        self::assertEqualsWithDelta(0.20, $this->totalOn('2026-10-24')['usd'], 1e-9);
    }

    public function testTheBoundaryFollowsTheConfiguredTimezoneAcrossADstChange(): void
    {
        // Europe/Berlin leaves DST on 2026-10-25 (03:00 -> 02:00); the local day still ends at local midnight.
        $this->setUpAt('2026-10-25T21:59:58+00:00'); // 23:59:58 CET (UTC+1) on the 25th
        $spend = $this->spend('Europe/Berlin');
        self::assertTrue($spend->reserve(0.10, 10000, 1.0, null));

        $this->clock->set(new DateTimeImmutable('2026-10-25T23:00:02+00:00')); // 00:00:02 on the 26th
        $spend->add(new Usage('m', 100, 0), 0.01);

        self::assertEqualsWithDelta(0.0, $this->totalOn('2026-10-25', 'Europe/Berlin')['usd'], 1e-9);
        self::assertEqualsWithDelta(0.01, $this->totalOn('2026-10-26', 'Europe/Berlin')['usd'], 1e-9);
    }

    public function testASettleOnTheSameDayStaysOneAdjustment(): void
    {
        $this->setUpAt('2026-10-23T10:00:00+00:00');
        $spend = $this->spend();
        self::assertTrue($spend->reserve(0.10, 10000, 1.0, null));
        $spend->add(new Usage('m', 1000, 500), 0.015);

        self::assertEqualsWithDelta(0.015, $spend->today()['usd'], 1e-9);
    }
}
