<?php

declare(strict_types=1);

namespace Watcher\Context;

use Watcher\Privacy\RedactedGroup;

/** FR-039: what a declared dependency looked like in the window. Holds redacted groups only. */
final class DependencyContext
{
    /**
     * @param array<string, int>       $totals
     * @param array<string, int>|null  $previousTotals
     * @param list<RedactedGroup>      $topErrors                 at most 5
     * @param list<int>                $referencedByTemplateIndexes
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $declared,
        public readonly array $totals,
        public readonly ?array $previousTotals,
        public readonly array $topErrors,
        public readonly array $referencedByTemplateIndexes,
        public readonly bool $firstSighting,
        public readonly bool $unavailable = false,
    ) {
    }
}
