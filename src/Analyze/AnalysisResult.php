<?php

declare(strict_types=1);

namespace Watcher\Analyze;

final class AnalysisResult
{
    /** @param list<Finding> $findings */
    public function __construct(
        public readonly array $findings,
    ) {
    }
}
