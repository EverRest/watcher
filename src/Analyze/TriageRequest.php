<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Privacy\RedactedGroup;

final class TriageRequest
{
    /** @param list<RedactedGroup> $groups */
    public function __construct(
        public readonly array $groups,
        public readonly string $serviceContext,
        public readonly string $model,
        public readonly int $maxOutputTokens,
    ) {
    }
}
