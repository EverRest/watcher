<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\BudgetAlerts;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class BudgetAlertsTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('UTC')));
        $this->store = new InMemoryStateStore($this->clock);
    }

    private function alerts(string $service = 'svc'): BudgetAlerts
    {
        return new BudgetAlerts($this->store, $this->clock, new DateTimeZone('UTC'), $service);
    }

    /**
     * @param array{usd: float, tokens: int} $before
     * @param array{usd: float, tokens: int} $after
     * @param list<int> $percents
     */
    private function check(BudgetAlerts $a, array $before, array $after, ?int $tokenLimit = null, float $usdLimit = 100.0, array $percents = [25, 50, 75, 90]): ?\Watcher\Analyze\Alert
    {
        return $a->check($before, $after, $usdLimit, $tokenLimit, $percents);
    }

    /** @return array<string, array{float, float, ?int}> before usd, after usd, expected percent */
    public static function costTable(): array
    {
        return [
            '24 to 26 crosses 25' => [24.0, 26.0, 25],
            '49 to 80 reports only 75' => [49.0, 80.0, 75],
            '91 from 0 reports 90' => [0.0, 91.0, 90],
            'no crossing' => [10.0, 20.0, null],
            'exactly on a threshold counts' => [10.0, 25.0, 25],
            'already above, no new threshold' => [51.0, 60.0, null],
            'no model call' => [30.0, 30.0, null],
            'over 100 still 90 at most' => [0.0, 250.0, 90],
        ];
    }

    #[DataProvider('costTable')]
    public function testCostThresholds(float $before, float $after, ?int $expected): void
    {
        $alert = $this->check($this->alerts(), ['usd' => $before, 'tokens' => 0], ['usd' => $after, 'tokens' => 0]);

        self::assertSame($expected, $alert?->percent);
        if ($alert !== null) {
            self::assertSame('cost', $alert->counter);
            self::assertSame($after, $alert->value);
            self::assertSame(100.0, $alert->limit);
        }
    }

    public function testAnnouncedThresholdIsNotRepeatedAndAllCrossedAreMarked(): void
    {
        $a = $this->alerts();
        self::assertSame(75, $this->check($a, ['usd' => 49.0, 'tokens' => 0], ['usd' => 80.0, 'tokens' => 0])?->percent);

        // 50 was marked too (crossed in the same run): crossing it "again" via a replay stays silent
        self::assertNull($this->check($a, ['usd' => 49.0, 'tokens' => 0], ['usd' => 55.0, 'tokens' => 0]));
        self::assertNull($this->check($a, ['usd' => 55.0, 'tokens' => 0], ['usd' => 76.0, 'tokens' => 0]));
        self::assertSame(90, $this->check($a, ['usd' => 76.0, 'tokens' => 0], ['usd' => 95.0, 'tokens' => 0])?->percent);
    }

    public function testSecondRunnerRacingGetsNothing(): void
    {
        $first = $this->alerts();
        $second = $this->alerts();

        self::assertSame(25, $this->check($first, ['usd' => 0.0, 'tokens' => 0], ['usd' => 30.0, 'tokens' => 0])?->percent);
        self::assertNull($this->check($second, ['usd' => 0.0, 'tokens' => 0], ['usd' => 30.0, 'tokens' => 0]));
    }

    public function testNextDayAnnouncesAgain(): void
    {
        $a = $this->alerts();
        $first = $this->check($a, ['usd' => 0.0, 'tokens' => 0], ['usd' => 30.0, 'tokens' => 0]);
        self::assertNotNull($first);

        $this->clock->advance('+1 day');

        $again = $this->check($a, ['usd' => 0.0, 'tokens' => 0], ['usd' => 30.0, 'tokens' => 0]);
        self::assertNotNull($again);
        self::assertSame(25, $again->percent);
    }

    public function testCountersAreIndependent(): void
    {
        $a = $this->alerts();

        $tokens = $this->check($a, ['usd' => 0.0, 'tokens' => 0], ['usd' => 0.0, 'tokens' => 600], 1000);
        self::assertSame('tokens', $tokens?->counter);
        self::assertSame(50, $tokens->percent);
        self::assertSame(600.0, $tokens->value);
        self::assertSame(1000.0, $tokens->limit);

        // the cost 50% is still unannounced
        self::assertSame('cost', $this->check($a, ['usd' => 0.0, 'tokens' => 600], ['usd' => 50.0, 'tokens' => 600], 1000)?->counter);
    }

    public function testHighestPercentWinsAcrossCounters(): void
    {
        $alert = $this->check($this->alerts(), ['usd' => 0.0, 'tokens' => 0], ['usd' => 30.0, 'tokens' => 910], 1000);

        self::assertSame('tokens', $alert?->counter);
        self::assertSame(90, $alert->percent);
    }

    public function testNullTokenLimitIgnoresTokens(): void
    {
        self::assertNull($this->check($this->alerts(), ['usd' => 0.0, 'tokens' => 0], ['usd' => 0.0, 'tokens' => 999999]));
    }

    public function testNoticeText(): void
    {
        $a = $this->alerts('m-svc');
        $cost = $a->check(['usd' => 0.0, 'tokens' => 0], ['usd' => 0.375, 'tokens' => 0], 0.5, null, [75]);
        self::assertSame('budget 75% — $0.38 of $0.50 spent today (m-svc)', $cost?->noticeText('m-svc'));

        $tok = $a->check(['usd' => 0.0, 'tokens' => 0], ['usd' => 0.0, 'tokens' => 7500], 0.5, 10000, [75]);
        self::assertSame('budget 75% — 7500 of 10000 tokens used today (m-svc)', $tok?->noticeText('m-svc'));
    }

    public function testAlertKeysFollowDataModel(): void
    {
        $this->check($this->alerts(), ['usd' => 0.0, 'tokens' => 0], ['usd' => 30.0, 'tokens' => 0]);

        self::assertArrayHasKey('alert:svc:2026-03-01:cost:25', $this->store->all());
        self::assertArrayNotHasKey('alert:svc:2026-03-01:cost:50', $this->store->all());
    }
}
