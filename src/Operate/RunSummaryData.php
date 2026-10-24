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
        public readonly ?string $environment = null,
        private readonly ?string $runId = null,
        /** @var array<string, int> */
        private readonly array $sinkFailures = [],
    ) {
    }

    /** Set only when an event sink was flushed. Private on purpose: the summary data stays byte-identical without sinks. */
    public function runId(): ?string
    {
        return $this->runId;
    }

    /** @return array<string, int> sink name => failures of this flush */
    public function sinkFailures(): array
    {
        return $this->sinkFailures;
    }

    /** The same data plus what the event sinks reported (additive; only when a sink was flushed). */
    /** @param array<string, int> $failures */
    public function withSinks(string $runId, array $failures): self
    {
        return new self($this->outcome, $this->service, $this->reason, $this->windowStart, $this->windowEnd, $this->stats, $this->todayUsd, $this->release, $this->dryRun, $this->environment, $runId, $failures);
    }
}
