<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use Watcher\Analyze\Alert;
use Watcher\Analyze\BudgetAlerts;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\State\StateStore;

/**
 * The fleet-wide daily counters: the existing Spend and BudgetAlerts under the id `fleet/{name}`, so they never meet a
 * service's own keys (service names cannot contain '/'). No cap configured = counters only, nothing is ever exhausted.
 */
final class FleetBudget
{
    private readonly Spend $spend;
    private readonly BudgetAlerts $alerts;

    /** @param list<int> $percents */
    public function __construct(
        StateStore $store,
        ClockInterface $clock,
        DateTimeZone $timezone,
        string $fleet,
        private readonly ?float $usdLimit,
        private readonly ?int $tokenLimit,
        private readonly array $percents,
    ) {
        $this->spend = new Spend($store, $clock, $timezone, 'fleet/' . $fleet);
        $this->alerts = new BudgetAlerts($store, $clock, $timezone, 'fleet/' . $fleet);
    }

    public function book(Usage $usage, float $costUsd): void
    {
        $this->spend->add($usage, $costUsd);
    }

    /** @return array{usd: float, tokens: int} */
    public function today(): array
    {
        return $this->spend->today();
    }

    public function exhausted(): bool
    {
        $t = $this->today();

        return ($this->usdLimit !== null && $t['usd'] >= $this->usdLimit) || ($this->tokenLimit !== null && $t['tokens'] >= $this->tokenLimit);
    }

    /** Whether a step whose worst case is this many dollars and tokens still fits under the fleet cap. */
    public function allows(float $worstUsd, int $tokens): bool
    {
        $t = $this->today();
        if ($this->tokenLimit !== null && $t['tokens'] + $tokens > $this->tokenLimit) {
            return false;
        }

        return $this->usdLimit === null || $t['usd'] + $worstUsd <= $this->usdLimit + 1e-9;
    }

    /**
     * @param array{usd: float, tokens: int} $before
     * @param array{usd: float, tokens: int} $after
     */
    public function alert(array $before, array $after): ?Alert
    {
        if ($this->usdLimit === null && $this->tokenLimit === null) {
            return null;
        }

        return $this->alerts->check($before, $after, $this->usdLimit ?? INF, $this->tokenLimit, $this->percents);
    }

    public function release(Alert $alert): void
    {
        $this->alerts->release($alert);
    }
}
