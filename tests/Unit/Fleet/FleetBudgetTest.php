<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\Fleet\FleetBudget;
use Watcher\Fleet\FleetSeam;
use Watcher\Operate\DryRunStateStore;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class FleetBudgetTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00'));
        $this->store = new InMemoryStateStore($this->clock);
    }

    private function budget(?float $usd = 1.0, ?int $tokens = null, ?StateStore $store = null): FleetBudget
    {
        return new FleetBudget($store ?? $this->store, $this->clock, new DateTimeZone('UTC'), 'platform', $usd, $tokens, [25, 50, 75, 90]);
    }

    public function testBookingAddsToTheServiceAndTheFleetCounters(): void
    {
        $seam = new FleetSeam($this->budget());
        $service = new Spend($this->store, $this->clock, new DateTimeZone('UTC'), 'api');
        $service->add(new Usage('m', 100, 50), 0.10);
        $seam->book(new Usage('m', 100, 50), 0.10);
        $seam->book(new Usage('m', 10, 5), 0.20);

        self::assertEqualsWithDelta(0.30, $seam->today()['usd'], 1e-9);
        self::assertSame(165, $seam->today()['tokens']);
        self::assertEqualsWithDelta(0.10, $service->today()['usd'], 1e-9, 'the service counter is its own');
    }

    public function testExhaustedCapIsFleetOnlyAtTheCap(): void
    {
        $seam = new FleetSeam($this->budget(1.0));
        self::assertNull($seam->exhaustedCap());
        $seam->book(new Usage('m', 1, 1), 0.99);
        self::assertNull($seam->exhaustedCap());
        $seam->book(new Usage('m', 1, 1), 0.01);
        self::assertSame('fleet', $seam->exhaustedCap());
    }

    public function testTokenCap(): void
    {
        $seam = new FleetSeam($this->budget(null, 100));
        $seam->book(new Usage('m', 60, 40), 0.0);

        self::assertSame('fleet', $seam->exhaustedCap());
    }

    public function testNoCapNeverExhausts(): void
    {
        $seam = new FleetSeam($this->budget(null, null));
        $seam->book(new Usage('m', 1000000, 1000000), 50.0);

        self::assertNull($seam->exhaustedCap());
        self::assertTrue($seam->allows(1000.0, 1000000));
    }

    public function testAllowsLooksAtWhatIsLeft(): void
    {
        $seam = new FleetSeam($this->budget(1.0, 1000));
        $seam->book(new Usage('m', 400, 100), 0.60);

        self::assertTrue($seam->allows(0.40, 500));
        self::assertFalse($seam->allows(0.41, 10), 'dollars');
        self::assertFalse($seam->allows(0.01, 501), 'tokens');
    }

    public function testThresholdsOncePerDayIndependentOfTheServiceOnes(): void
    {
        $b = $this->budget(1.0);
        $before = $b->today();
        $b->book(new Usage('m', 1, 1), 0.55);
        $alert = $b->alert($before, $b->today());

        self::assertNotNull($alert);
        self::assertSame(50, $alert->percent);
        self::assertStringContainsString('platform', $alert->noticeText('platform'));

        $same = $b->today();
        $b->book(new Usage('m', 1, 1), 0.01);
        self::assertNull($b->alert($same, $b->today()), 'no new threshold');

        // a service alert with the same percent does not consume the fleet claim, nor the other way round
        $service = new \Watcher\Analyze\BudgetAlerts($this->store, $this->clock, new DateTimeZone('UTC'), 'api');
        self::assertNotNull($service->check(['usd' => 0.0, 'tokens' => 0], ['usd' => 0.55, 'tokens' => 0], 1.0, null, [25, 50, 75, 90]));
    }

    public function testAReleasedAlertCanBeClaimedAgain(): void
    {
        $b = $this->budget(1.0);
        $before = $b->today();
        $b->book(new Usage('m', 1, 1), 0.55);
        $alert = $b->alert($before, $b->today());
        self::assertNotNull($alert);
        self::assertNull($b->alert($before, $b->today()), 'claimed');

        $b->release($alert);

        self::assertNotNull($b->alert($before, $b->today()));
    }

    public function testNoUsdCapMeansNoAlert(): void
    {
        $b = $this->budget(null, null);
        $before = $b->today();
        $b->book(new Usage('m', 1, 1), 5.0);

        self::assertNull($b->alert($before, $b->today()));
    }

    public function testDryRunBooksNothing(): void
    {
        $seam = new FleetSeam($this->budget(1.0, null, new DryRunStateStore($this->store)));
        $seam->book(new Usage('m', 10, 10), 0.5);

        self::assertSame(0.0, $seam->today()['usd']);
        self::assertSame(0.0, (new FleetSeam($this->budget(1.0)))->today()['usd']);
    }

    public function testCountersAreSeparateEachDay(): void
    {
        $seam = new FleetSeam($this->budget(1.0));
        $seam->book(new Usage('m', 1, 1), 1.0);
        $this->clock->advance('+1 day');

        self::assertNull($seam->exhaustedCap());
    }
}
