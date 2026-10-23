<?php

declare(strict_types=1);

namespace Watcher\Select;

use Watcher\Reduce\ProblemGroup;

final class Candidate
{
    /** @param list<CandidateReason> $reasons */
    public function __construct(
        public readonly ProblemGroup $group,
        public readonly array $reasons,
        public readonly int $priority,
    ) {
    }
}
