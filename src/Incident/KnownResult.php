<?php

declare(strict_types=1);

namespace Watcher\Incident;

use Watcher\Reduce\ProblemGroup;

final class KnownResult
{
    /**
     * @param list<ProblemGroup>    $kept
     * @param list<KnownLine>       $lines
     * @param array<string, true>   $grown
     * @param array<string, string> $labels
     */
    public function __construct(
        public readonly array $kept,
        public readonly array $lines,
        public readonly array $grown,
        public readonly array $labels,
        public readonly int $knownCount,
        public readonly int $knownGrownCount,
    ) {
    }
}
