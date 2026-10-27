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
        /** analysis.max_input_tokens.escalate: an escalation sends at most this many estimated input tokens */
        public readonly int $escalateMaxInputTokens = PHP_INT_MAX,
        /** analysis.triage.enabled: false = every fresh group goes straight to analysis, no triage call */
        public readonly bool $triageEnabled = true,
        /** analysis.advice.enabled: false = the analysis prompt has no how_to_check / related_checks */
        public readonly bool $advice = true,
        /** Per-run random salt for the top_m tie-break, so a fingerprint cannot be ground to sort first. */
        public readonly string $tieSalt = '',
        /** spec 009: measured metrics for the payload's `m` section, already fitted to max_input_tokens; null = none */
        public readonly ?PayloadExtras $metrics = null,
    ) {
    }
}
