<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Closure;

/**
 * FR-035 (research R5): a dry run bills real tokens and writes no spend, so the stored counter alone cannot stop a loop. Order: busy,
 * confirmation (strict boolean true), daily limit already used, estimate + escalation allowance against what is left after the
 * in-process counter. Every refusal makes zero model calls. The counter resets with the process, like a human re-running the command.
 */
final class DryRunGuard
{
    private float $spentUsd = 0.0;
    private int $spentTokens = 0;
    private bool $running = false;

    /** @param Closure(): array{usd: float, tokens: int} $today the stored spend of the day */
    public function __construct(private readonly Closure $today, private readonly float $budgetUsd, private readonly ?int $tokenLimit)
    {
    }

    /** @return array{usd: float, tokens: int, budget_usd: float, token_limit: int|null, remaining_usd: float} */
    public function spendToday(): array
    {
        $t = ($this->today)();

        return ['usd' => $t['usd'], 'tokens' => $t['tokens'], 'budget_usd' => $this->budgetUsd, 'token_limit' => $this->tokenLimit, 'remaining_usd' => round($this->budgetUsd - $t['usd'] - $this->spentUsd, 6)];
    }

    /**
     * @return array{usd: float, tokens: int} the upper bound that a run would add to the counter
     * @throws ToolError
     */
    public function admit(mixed $confirmCost, DryRunPipeline $pipeline): array
    {
        if ($this->running) {
            throw new ToolError('busy');
        }
        if ($confirmCost !== true) {
            throw new ToolError('cost_not_confirmed', ['spend_today' => $this->spendToday()]);
        }
        $today = ($this->today)();
        $usedUsd = $today['usd'] + $this->spentUsd;
        $usedTokens = $today['tokens'] + $this->spentTokens;
        if ($usedUsd >= $this->budgetUsd || ($this->tokenLimit !== null && $usedTokens >= $this->tokenLimit)) {
            throw new ToolError('daily_limit_reached', ['spend_today' => $this->spendToday()]);
        }
        $allowance = $pipeline->escalationAllowanceUsd();
        $usd = 0.0;
        $tokens = 0;
        foreach ($pipeline->estimate() as $step) {
            $usd += $step['cost_usd'];
            $tokens += $step['tokens_in'] + $step['tokens_out_max'];
        }
        $remaining = $this->budgetUsd - $usedUsd;
        if ($allowance === null) {
            throw new ToolError('would_exceed_daily_limit', ['estimate_usd' => null, 'remaining_usd' => round($remaining, 6), 'spend_today' => $this->spendToday()]); // an unpriced escalation model cannot be bounded: refuse
        }
        $usd += $allowance;
        if ($usd > $remaining + 1e-9 || ($this->tokenLimit !== null && $usedTokens + $tokens > $this->tokenLimit)) {
            throw new ToolError('would_exceed_daily_limit', ['estimate_usd' => round($usd, 6), 'remaining_usd' => round($remaining, 6), 'spend_today' => $this->spendToday()]);
        }

        return ['usd' => $usd, 'tokens' => $tokens];
    }

    public function begin(): void
    {
        $this->running = true;
    }

    /** @param array{usd: float, tokens: int}|null $consumed added to the counter after a full run */
    public function end(?array $consumed): void
    {
        $this->running = false;
        if ($consumed !== null) {
            $this->spentUsd += $consumed['usd'];
            $this->spentTokens += $consumed['tokens'];
        }
    }

    public function busy(): bool
    {
        return $this->running;
    }
}
