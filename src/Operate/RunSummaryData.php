<?php

declare(strict_types=1);

namespace Watcher\Operate;

use DateTimeInterface;
use Watcher\RunOutcome;

/**
 * What the JSON summary is built from. Every unknown value is null (invalid config, skips).
 * `$reason` is the run's failure reason as it stands; JsonSummary lets it through only when it is a short code.
 */
final class RunSummaryData
{
    public function __construct(
        public readonly RunOutcome $outcome,
        public readonly ?string $service = null,
        public readonly ?string $reason = null,
        public readonly ?DateTimeInterface $windowStart = null,
        public readonly ?DateTimeInterface $windowEnd = null,
        public readonly ?RunStats $stats = null,
        public readonly ?float $todayUsd = null,
        public readonly ?string $release = null,
        public readonly bool $dryRun = false,
    ) {
    }
}
