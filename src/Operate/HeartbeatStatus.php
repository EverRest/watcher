<?php

declare(strict_types=1);

namespace Watcher\Operate;

use DateTimeImmutable;

/** Answer of {@see Heartbeat::status()}. `state` is ok|stale|failed|unknown; only `ok` is healthy. Not stored. */
final class HeartbeatStatus
{
    /** @param array<mixed>|null $record the stored heartbeat record */
    public function __construct(
        public readonly string $state,
        public readonly ?array $record,
        public readonly ?int $ageSeconds,
        public readonly int $limitSeconds,
        public readonly DateTimeImmutable $now,
        public readonly ?DateTimeImmutable $comparedWith,
        public readonly ?DateTimeImmutable $lastTriggerAt,
    ) {
    }

    public function ok(): bool
    {
        return $this->state === 'ok';
    }
}
