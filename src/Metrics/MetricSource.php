<?php

declare(strict_types=1);

namespace Watcher\Metrics;

use DateTimeImmutable;
use Watcher\Source\SourceUnavailable;

/**
 * Optional, read-only metric backend (spec 009). Returns plain numbers: labels are dropped inside the adapter, so a
 * caller cannot leak one by forgetting to. Failure messages are one of: unreachable, unauthorized, timeout,
 * bad_response, rate_limited, too_large.
 */
interface MetricSource
{
    /** @throws SourceUnavailable */
    public function instant(string $query, DateTimeImmutable $at): MetricResult;

    /** @throws SourceUnavailable */
    public function range(string $query, DateTimeImmutable $start, DateTimeImmutable $end, int $stepSeconds): MetricResult;
}
