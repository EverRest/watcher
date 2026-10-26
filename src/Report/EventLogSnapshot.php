<?php

declare(strict_types=1);

namespace Watcher\Report;

/** Parsed events for one week after allowlist filtering. */
final class EventLogSnapshot
{
    /**
     * @param array<string, array<string, mixed>> $runs keyed by window_end
     * @param array<string, array<string, mixed>> $groups keyed by window_end\0fingerprint
     * @param array<string, int> $skipped
     */
    public function __construct(
        public readonly array $runs,
        public readonly array $groups,
        public readonly array $skipped,
        public readonly string $path,
    ) {
    }

    public function skippedTotal(): int
    {
        return array_sum($this->skipped);
    }
}
