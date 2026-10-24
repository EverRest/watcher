<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Fleet\FleetSeam;

/**
 * Per-step check against the hard daily cap: a step runs only if its worst case still fits in what is left.
 * "Left" is read from Spend each time, and every call is booked there as it returns, so what this run already paid is included.
 * On Redis the worst case is reserved in the same atomic step as the check and settled to the real cost when the call returns (M2).
 * Worst case = planned input tokens + max_output_tokens at the configured prices (one attempt: a retry on invalid output can add one more).
 */
final class BudgetReservation
{
    public function __construct(
        private readonly Spend $spend,
        private readonly CostCalculator $calculator,
        private readonly float $dailyBudgetUsd,
        private readonly ?int $dailyTokenLimit,
        private readonly ?FleetSeam $fleet = null,
    ) {
    }

    public function allows(string $model, int $inputTokens, int $maxOutputTokens): bool
    {
        $worst = $this->worstCase($model, $inputTokens, $maxOutputTokens);
        if ($this->fleet !== null && !$this->fleet->allows($worst, $inputTokens + $maxOutputTokens)) {
            return false; // the fleet's remaining counts as well as the service's
        }

        return $this->spend->reserve($worst, $inputTokens + $maxOutputTokens, $this->dailyBudgetUsd, $this->dailyTokenLimit);
    }

    private function worstCase(string $model, int $in, int $out): float
    {
        try {
            // The system prompt is sent with cache_control, so the input may be billed at the cache-write price.
            return max($this->calculator->cost(new Usage($model, $in, $out)), $this->calculator->cost(new Usage($model, 0, $out, 0, $in)));
        } catch (UnknownModelPrice) {
            return 0.0; // booked at 0 and warned about when the call is made (Watcher::metered)
        }
    }
}
