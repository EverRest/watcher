<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Context\DependencyContext;
use Watcher\Privacy\RedactedGroup;

final class AnalysisRequest
{
    /**
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $dependencyContext already redacted; indexes refer to positions in $groups
     */
    public function __construct(
        public readonly array $groups,
        public readonly string $serviceContext,
        public readonly string $model,
        public readonly int $maxOutputTokens,
        public readonly array $dependencyContext = [],
        public readonly ?PayloadExtras $extras = null,
        /** @var array<string, string> fingerprint => `ep`, the masked one-line endpoint documentation (spec 010) */
        public readonly array $endpoints = [],
        /** The step whose provider serves this call: 'analyze' or 'escalate' (spec 016). */
        public readonly string $step = 'analyze',
    ) {
    }
}
