<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Select\Candidate;

final class TruncationResult
{
    /** @param list<Candidate> $kept */
    public function __construct(public readonly array $kept, public readonly int $droppedCount)
    {
    }
}
