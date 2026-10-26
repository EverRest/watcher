<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use DateTimeImmutable;

/** One ledger entry as seen through a window: the in-window occurrences and the fields needed to build a LogEvent. */
final class LedgerEntry
{
    public function __construct(
        public readonly string $type,
        public readonly string $reason,
        public readonly string $workloadKind,
        public readonly string $workload,
        public readonly string $text,
        public readonly ?string $podHash,
        public readonly bool $restarted,
        public readonly int $count,
        public readonly DateTimeImmutable $first,
        public readonly DateTimeImmutable $last,
    ) {
    }
}
