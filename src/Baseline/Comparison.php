<?php

declare(strict_types=1);

namespace Watcher\Baseline;

use DateTimeImmutable;
use Watcher\Reduce\GroupStatus;

/**
 * What a window is compared with: the earlier snapshots used, and one comparison value per fingerprint seen in them.
 * Values are events per current window length (older snapshots are scaled to it). Counts only; no text.
 */
final class Comparison
{
    /**
     * @param list<DateTimeImmutable>            $ends         window ends actually used, ascending
     * @param array<string, float>               $values       fingerprint => comparison value, for fingerprints seen in a used snapshot
     * @param array<string, true>                $rare         fingerprints whose typical value is 0 but that were seen (value is then the maximum)
     * @param array{float, float}|null           $lengthChange [hours of an earlier window, hours of this one] when they differ
     * @param string|null                        $reason       'short_history' when the requested mode had too little history
     */
    public function __construct(
        public readonly string $requestedMode,
        public readonly string $usedMode,
        public readonly ?string $reason,
        public readonly array $ends,
        public readonly int $candidates,
        public readonly int $found,
        public readonly int $excluded,
        public readonly array $values,
        public readonly array $rare,
        public readonly float $growthFactor,
        public readonly ?array $lengthChange = null,
    ) {
    }

    /** True when no snapshot was usable (first run, or everything older than the maximum age). */
    public function isBaseline(): bool
    {
        return $this->ends === [];
    }

    public function statusFor(string $fingerprint, int $count): GroupStatus
    {
        if (!isset($this->values[$fingerprint])) {
            return GroupStatus::resolve($count, null, $this->growthFactor);
        }
        $status = GroupStatus::resolve($count, $this->values[$fingerprint], $this->growthFactor, true);

        return isset($this->rare[$fingerprint]) && $status === GroupStatus::Unchanged ? GroupStatus::Expected : $status;
    }

    public function previousFor(string $fingerprint): ?int
    {
        return isset($this->values[$fingerprint]) ? (int) round($this->values[$fingerprint]) : null;
    }
}
