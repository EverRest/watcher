<?php

declare(strict_types=1);

namespace Watcher\Context;

/** What the resolver decided: which declared dependencies need a line read, and why. */
final class DependencyPlan
{
    /**
     * @param list<string>                $linesFor       declared deps whose error lines must be read
     * @param array<string, list<int>>    $referencedBy   dep name => indexes (into the candidate template list) that mention it
     * @param int                         $undeclaredHosts number of `<URL>`/`<HOST>` placeholders (undeclared, so unnamed) in the distinct candidate templates
     * @param array<string, bool>         $firstSighting  dep name => true when no previous snapshot exists
     */
    public function __construct(
        public readonly array $linesFor = [],
        public readonly array $referencedBy = [],
        public readonly int $undeclaredHosts = 0,
        public readonly array $firstSighting = [],
    ) {
    }
}
