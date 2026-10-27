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
     * @param list<array{step: string, groups: int}> $steps
     * @param (Closure(): array<string, mixed>)|null $onRun
     */
    public function __construct(public array $steps = [['step' => 'triage', 'groups' => 10], ['step' => 'analyze', 'groups' => 5]], private readonly ?Closure $onRun = null)
    {
    }

    public function estimate(): array
    {
        ++$this->estimates;

        return $this->steps;
    }

    public function run(): array
    {
        ++$this->runs;
        if ($this->onRun !== null) {
            return ($this->onRun)();
        }

        return [
            'window_start' => '2026-10-24T06:00:00Z', 'window_end' => '2026-10-25T06:00:00Z', 'at' => '2026-10-25T09:00:00Z', 'outcome' => 'reported',
            'counts' => ['events' => 10, 'groups' => 4, 'candidates' => 2, 'findings' => 1],
            'findings' => [['fp' => 'a3f9c01d22be', 'severity' => 'error', 'title' => 'Timeouts']],
        ];
    }
}
