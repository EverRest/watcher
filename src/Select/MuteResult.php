<?php

declare(strict_types=1);

namespace Watcher\Select;

use Watcher\Reduce\ProblemGroup;

final class MuteResult
{
    /** @param list<ProblemGroup> $kept */
    public function __construct(public readonly array $kept, public readonly int $mutedCount)
    {
    }
}
