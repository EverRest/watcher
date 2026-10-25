<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use Closure;
use Watcher\Mcp\DryRunPipeline;

final class FakePipeline implements DryRunPipeline
{
    public int $runs = 0;
    public int $estimates = 0;

    /**
     * @param array{usd: float, tokens: int}|null $allowance
     * @param list<array{step: string, groups: int, tokens_in: int, tokens_out_max: int, cost_usd: float}> $steps
     * @param (Closure(): array<string, mixed>)|null $onRun
     */
    public function __construct(public array $steps = [['step' => 'triage', 'groups' => 10, 'tokens_in' => 1000, 'tokens_out_max' => 500, 'cost_usd' => 0.01], ['step' => 'analyze', 'groups' => 5, 'tokens_in' => 4000, 'tokens_out_max' => 1500, 'cost_usd' => 0.05]], public ?array $allowance = ['usd' => 0.04, 'tokens' => 1000], private readonly ?Closure $onRun = null)
    {
    }

    public function estimate(): array
    {
        ++$this->estimates;

        return $this->steps;
    }

    public function escalationAllowance(): ?array
    {
        return $this->allowance;
    }

    public function run(): array
    {
        ++$this->runs;
        if ($this->onRun !== null) {
            return ($this->onRun)();
        }

        return [
            'window_start' => '2026-10-24T06:00:00Z', 'window_end' => '2026-10-25T06:00:00Z', 'at' => '2026-10-25T09:00:00Z', 'outcome' => 'reported',
            'counts' => ['events' => 10, 'groups' => 4, 'candidates' => 2, 'findings' => 1], 'tokens' => ['in' => 5000, 'out' => 900, 'cached' => 0], 'cost_usd' => 0.07,
            'findings' => [['fp' => 'a3f9c01d22be', 'severity' => 'error', 'title' => 'Timeouts']],
        ];
    }
}
