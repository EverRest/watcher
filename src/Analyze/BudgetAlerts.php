<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/** FR-046: announce each (counter, percent) at most once per day, only the highest newly crossed. */
final class BudgetAlerts
{
    public function __construct(
        private readonly StateStore $store,
        private readonly ClockInterface $clock,
        private readonly DateTimeZone $timezone,
        private readonly string $service,
    ) {
    }

    /**
     * Claims every newly crossed, not yet announced threshold; returns the highest one claimed here.
     *
     * @param array{usd: float, tokens: int} $before
     * @param array{usd: float, tokens: int} $after
     * @param list<int> $percents
     */
    public function check(array $before, array $after, float $usdLimit, ?int $tokenLimit, array $percents): ?Alert
    {
        $day = $this->clock->now()->setTimezone($this->timezone)->format('Y-m-d');
        $counters = [['cost', (float) $before['usd'], (float) $after['usd'], $usdLimit]];
        if ($tokenLimit !== null) {
            $counters[] = ['tokens', (float) $before['tokens'], (float) $after['tokens'], (float) $tokenLimit];
        }

        $best = null;
        $keys = [];
        foreach ($counters as [$counter, $was, $now, $limit]) {
            foreach ($percents as $percent) {
                $at = $limit * $percent / 100;
                if (!($was < $at && $now >= $at)) {
                    continue;
                }
                $key = Keys::alert($this->service, $day, $counter, $percent);
                if (!$this->store->acquire($key, 'alert', Keys::ttl(Keys::ALERT, 0, 0))) {
                    continue;
                }
                $keys[] = $key;
                if ($best === null || $percent > $best[1]) {
                    $best = [$counter, $percent, $now, $limit];
                }
            }
        }

        return $best === null ? null : new Alert($best[0], $best[1], $best[2], $best[3], $keys);
    }

    /** The notice could not be sent: give the claims back so the next run announces it instead of losing it for the day. */
    public function release(Alert $alert): void
    {
        foreach ($alert->claimed as $key) {
            $this->store->release($key, 'alert');
        }
    }
}
