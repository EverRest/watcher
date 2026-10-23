<?php

declare(strict_types=1);

namespace Watcher\Analyze;

final class RouterResult
{
    /**
     * @param list<Finding>        $findings
     * @param array<string, Usage> $usageByStep keys triage|analyze|escalate, only steps that ran
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $usageByStep,
        public readonly int $escalations,
        public readonly int $reused,
        public readonly int $triaged,
        public readonly int $droppedByTopM,
    ) {
    }
}
