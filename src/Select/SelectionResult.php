<?php

declare(strict_types=1);

namespace Watcher\Select;

final class SelectionResult
{
    /** @param list<Candidate> $candidates ordered by priority desc, then fingerprint */
    public function __construct(
        public readonly array $candidates,
        public readonly int $otherGroups,
        public readonly int $otherEvents,
    ) {
    }
}
