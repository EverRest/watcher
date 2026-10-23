<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class SpendTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('UTC')));
        $this->store = new InMemoryStateStore($this->clock);
    }

    private function spend(string $tz = 'UTC', string $service = 'svc'): Spend
    {
        return new Spend($this->store, $this->clock, new DateTimeZone($tz), $service);
    }

    public function testStartsAtZero(): void
    {
        self::assertSame(['usd' => 0.0, 'tokens' => 0], $this->spend()->today());
    }

    public function testAddAccumulatesUsdAndAllTokenKinds(): void
    {
        $s = $this->spend();
        $s->add(new Usage('m', 100, 50, 20, 10), 0.25);
        $s->add(new Usage('m', 1, 1), 0.10);

        $today = $s->today();
        self::assertEqualsWithDelta(0.35, $today['usd'], 1e-9);
        self::assertSame(182, $today['tokens']);
    }

    public function testDayRollsOverInConfiguredTimezone(): void
    {
        $this->clock->set(new DateTimeImmutable('2026-03-01 23:30:00', new DateTimeZone('UTC')));
        $utc = $this->spend('UTC');
        $kyiv = $this->spend('Europe/Kyiv'); // already 2026-03-02 01:30

        $utc->add(new Usage('m', 10, 0), 1.0);
        self::assertSame(0, $kyiv->today()['tokens']);

        $this->clock->advance('+1 hour');
        self::assertSame(0, $utc->today()['tokens']);
        self::assertSame('2026-03-02', $utc->day());
    }

    public function testServicesAreIndependent(): void
    {
        $this->spend('UTC', 'a')->add(new Usage('m', 10, 0), 1.0);

        self::assertSame(0, $this->spend('UTC', 'b')->today()['tokens']);
    }

    public function testExhaustedAtOrAboveLimit(): void
    {
        $s = $this->spend();
        $s->add(new Usage('m', 100, 0), 0.50);

        self::assertTrue($s->exhausted(0.50, null));
        self::assertFalse($s->exhausted(0.51, null));
        self::assertTrue($s->exhausted(10.0, 100));
        self::assertFalse($s->exhausted(10.0, 101));
        self::assertFalse($s->exhausted(10.0, null));
    }

    public function testCountersExpireAfterTwoDays(): void
    {
        $this->spend()->add(new Usage('m', 5, 0), 1.0);
        self::assertNotEmpty($this->store->all());

        $this->clock->advance('+2 days +1 second');

        self::assertSame([], $this->store->all());
    }
}
