<?php

declare(strict_types=1);

namespace Watcher\Report;

/** Counts and list entries for the weekly report (no free text). */
final class ReportData
{
    /**
     * @param array{reported: int, quiet: int, baselined: int, failed: int} $runs
     * @param list<array{fingerprint: string, level: string, days: int, days_total: int, known: ?string}> $recurring
     * @param list<array{fingerprint: string, release: string}> $stopped
     * @param list<array{fingerprint: string, level: string, count: int}> $noisiest
     */
    public function __construct(
        public readonly string $service,
        public readonly string $environment,
        public readonly string $isoWeek,
        public readonly string $rangeStart,
        public readonly string $rangeEnd,
        public readonly array $runs,
        public readonly array $recurring,
        public readonly array $stopped,
        public readonly array $noisiest,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
        public readonly float $costUsd,
        public readonly int $muted,
        public readonly int $knownSeen,
        public readonly int $knownTotal,
        public readonly int $topGroupsPerRun,
        public readonly int $linesSkipped,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'service' => $this->service,
            'environment' => $this->environment,
            'week' => $this->isoWeek,
            'range_start' => $this->rangeStart,
            'range_end' => $this->rangeEnd,
            'runs' => $this->runs,
            'recurring' => $this->recurring,
            'stopped' => $this->stopped,
            'noisiest' => $this->noisiest,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'cost_usd' => $this->costUsd,
            'muted' => $this->muted,
            'known_seen' => $this->knownSeen,
            'known_total' => $this->knownTotal,
            'top_groups_per_run' => $this->topGroupsPerRun,
            'lines_skipped' => $this->linesSkipped,
        ];
    }
}
