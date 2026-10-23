<?php

declare(strict_types=1);

namespace Watcher\Select;

use Watcher\Reduce\ProblemGroup;

final class MuteResult
{
    /**
     * @param list<ProblemGroup>                  $kept
     * @param array<string, array{int, int, int}> $partial fingerprint => [events matched, events in group, mute entry index]
     *                                                     for kept groups some of whose events matched a field mute
     */
    public function __construct(public readonly array $kept, public readonly int $mutedCount, public readonly array $partial = [])
    {
    }
}
