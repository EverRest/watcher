<?php

declare(strict_types=1);

namespace Watcher\Metrics;

/** One metric reduced to two numbers. `state`: ok | no_data | no_baseline | ambiguous. Labels are already masked. */
final class MetricReading
{
    /** @param list<string> $labels at most 5, masked */
    public function __construct(
        public readonly string $name,
        public readonly string $unit,
        public readonly ?float $current,
        public readonly ?float $baseline,
        public readonly string $state,
        public readonly int $series,
        public readonly array $labels,
    ) {
    }

    /** Null unless both numbers exist, the baseline is positive and the ratio is finite (a zero baseline has no meaningful percent). */
    public function relativeChange(): ?float
    {
        if ($this->current === null || $this->baseline === null || !($this->baseline > 0)) { // also NAN and -0.0
            return null;
        }
        $ratio = ($this->current - $this->baseline) / $this->baseline;

        return is_finite($ratio) ? $ratio : null; // a tiny baseline overflows to INF: no ratio, never a number that breaks json_encode or an int cast
    }

    public function absoluteChange(): ?float
    {
        return $this->current === null || $this->baseline === null ? null : $this->current - $this->baseline;
    }
}
