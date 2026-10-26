<?php

declare(strict_types=1);

namespace Watcher\Issues;

use Watcher\Reduce\ProblemGroup;

/** Groups built from issues, the fingerprints that are regressions (digest label only), and how many issues were dropped. */
final class IssueGroups
{
    /**
     * @param list<ProblemGroup> $groups
     * @param list<string>       $regressions
     */
    public function __construct(public readonly array $groups, public readonly array $regressions, public readonly int $dropped)
    {
    }
}
