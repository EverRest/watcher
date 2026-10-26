<?php

declare(strict_types=1);

namespace Watcher\Metrics;

/**
 * Finite samples only. `series` counts the series the backend returned before filtering; empty `values` means no data
 * (never zero). `allowedLabelValues` are raw: the reader masks them before anything is shown.
 */
final class MetricResult
{
    /**
     * @param list<float>  $values              one per series (instant) or per sample (range, single series)
     * @param list<string> $allowedLabelValues  at most 5, from allowlisted label names only
     * @param list<int>    $timestamps          range only, aligned with `values`
     */
    public function __construct(
        public readonly array $values,
        public readonly int $series,
        public readonly array $allowedLabelValues = [],
        public readonly array $timestamps = [],
    ) {
    }
}
