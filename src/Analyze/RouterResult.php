<?php

declare(strict_types=1);

namespace Watcher\Analyze;

final class RouterResult
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public readonly array $findings,
        public readonly int $escalations,
        public readonly int $reused,
        public readonly int $triaged,
        public readonly int $droppedByTopM,
        /** @var int interesting groups that triage flagged but no analysis looked at (triage-only day or top_m cut) */
        public readonly int $notAnalysed = 0,
        /** @var int escalations not made because they exceeded analysis.max_input_tokens.escalate even after trimming */
        public readonly int $escalationsSkippedSize = 0,
        /** @var int error-or-higher / watch-rule groups the analysis reported nothing for: not remembered, analysed again next window */
        public readonly int $unreported = 0,
        /** @var int groups triage dropped as noise/known (low-level groups only) */
        public readonly int $triageDropped = 0,
        /** @var int error-or-higher / watch-rule groups triage tried to silence (noise/known/missing): analysed anyway */
        public readonly int $triageCoerced = 0,
        /** @var int new groups left out of top_m by the per-run cap on new groups while older ones took the slots */
        public readonly int $newOverflow = 0,
    ) {
    }
}
