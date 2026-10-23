<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use Watcher\Schedule\Window;

interface LogSource
{
    /**
     * Backend-side totals per level for the window, scoped by stream tags (FR-012a, FR-036).
     *
     * @return array<string, int> level => count
     * @throws SourceUnavailable network/auth/limit failure
     */
    public function totals(Window $window, StreamSelector $selector): array;

    /**
     * Server-filtered lines (level >= $filter->minLevel OR any $filter->watchPatterns), paged,
     * stopping at $filter->eventCap. Yields lazily so the reducer never holds all events.
     *
     * @return iterable<LogEvent>
     * @throws SourceUnavailable
     */
    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): iterable;

    /**
     * True when the most recent `lines()` stream stopped at `eventCap` while more matching lines remained.
     * Meaningful only after that generator has been consumed; false before any read.
     */
    public function lastReadTruncated(): bool;

    /**
     * Deploy versions seen in the window via a per-line label (FR-041).
     *
     * @return array<string, DateTimeImmutable> version => first seen in window; [] if label absent
     * @throws SourceUnavailable
     */
    public function versions(Window $window, StreamSelector $selector, string $label): array;
}
