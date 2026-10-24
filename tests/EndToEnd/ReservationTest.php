<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\State\RedisStateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeRedisServer;

/** M2 through a whole run on a store that reserves (Redis, emulated): what is reserved is settled, given back, or booked, never left behind. */
final class ReservationTest extends TestCase
{
    private function harness(): Harness
    {
        $h = new Harness(['analysis' => ['daily_budget_usd' => 100.0, 'prices' => ['m-t' => [1000, 0, 0, 0], 'm-a' => [1000, 0, 0, 0]]]]);
        $h->state = new RedisStateStore(new FakeRedisServer($h->clock), $h->clock);

        return $h;
    }

    /** @return array{usd: float, tokens: int} */
    private function today(Harness $h): array
    {
        return (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->today();
    }

    private function source(string ...$types): FakeLogSource
    {
        $events = [];
        foreach ($types as $t) {
            $events = [...$events, ...Harness::events(Level::Error, "$t failed in a unique way", 5, $t)];
        }

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    public function testASuccessfulRunBooksExactlyWhatEachCallCost(): void
    {
        $h = $this->harness();
        $h->watcher($this->source('Alpha'))->run();
        $h->nextWindow();

        $result = $h->watcher($this->source('Alpha', 'Beta'))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $today = $this->today($h);
        self::assertEqualsWithDelta(2.4, $today['usd'], 1e-9, 'same total as on a store that cannot reserve');
        self::assertSame(3000, $today['tokens']);
    }

    public function testAFailedCallWithoutUsageGivesItsReservationBack(): void
    {
        $h = $this->harness();
        $h->watcher($this->source('Alpha'))->run();
        $h->nextWindow();
        $h->analyzer->failure = new AnalyzerFailed('provider unavailable');

        $h->watcher($this->source('Alpha', 'Beta'))->run();

        $today = $this->today($h);
        self::assertSame(0, $today['tokens']);
        self::assertEqualsWithDelta(0.0, $today['usd'], 1e-9);
    }

    public function testAFailedCallWithBilledAttemptsBooksThemAndNothingMore(): void
    {
        $h = $this->harness();
        $h->watcher($this->source('Alpha'))->run();
        $h->nextWindow();
        $h->analyzer->failure = new AnalyzerFailed('invalid model output', new Usage('m-t', 1000, 200));

        $h->watcher($this->source('Alpha', 'Beta'))->run();

        $today = $this->today($h);
        self::assertEqualsWithDelta(1.0, $today['usd'], 1e-9);
        self::assertSame(1200, $today['tokens']);
    }
}
