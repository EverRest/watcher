<?php

declare(strict_types=1);

namespace Watcher\Analyze;

final class RouterSettings
{
    public function __construct(
        public readonly string $triageModel,
        public readonly string $analyzeModel,
        public readonly string $escalateModel,
        public readonly int $triageMaxTokens,
        public readonly int $analyzeMaxTokens,
        public readonly int $escalateMaxTokens,
        public readonly int $topM,
        public readonly int $escalationCap,
        public readonly float $confidenceThreshold,
        public readonly float $growthFactor,
        public readonly string $serviceContext,
    ) {
    }
}
