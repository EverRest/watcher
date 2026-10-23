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
        /** @var int interesting groups that triage flagged but no analysis looked at (triage-only day or top_m cut) */
        public readonly int $notAnalysed = 0,
        /** @var int groups never sent to triage because its worst case did not fit the daily budget */
        public readonly int $notTriaged = 0,
        /** @var int escalations not made because their worst case did not fit the daily budget (the first-pass finding is kept) */
        public readonly int $escalationsSkippedBudget = 0,
        /** @var int escalations not made because they exceeded analysis.max_input_tokens.escalate even after trimming */
        public readonly int $escalationsSkippedSize = 0,
    ) {
    }
}
