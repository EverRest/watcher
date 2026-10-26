<?php

declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Select\Candidate;

/** Synthetic anomaly candidates, which metric each stands for, and how many were cut by `max_anomalies`. */
final class AnomalyResult
{
    /**
     * @param list<Candidate>       $candidates
     * @param array<string, string> $metricByFingerprint fingerprint => declared metric name
     */
    public function __construct(public readonly array $candidates, public readonly array $metricByFingerprint, public readonly int $truncated)
    {
    }
}
