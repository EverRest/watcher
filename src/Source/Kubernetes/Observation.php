<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use DateTimeImmutable;
use JsonSerializable;

/** One Kubernetes event (or pod-status signal) reduced to a closed set of masked fields. Never persisted as is. */
final class Observation implements JsonSerializable
{
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $workloadKind,
        public readonly string $workload,
        public readonly string $reason,
        public readonly string $type,
        public readonly string $text,
        public readonly int $count,
        public readonly DateTimeImmutable $first,
        public readonly DateTimeImmutable $last,
        public readonly ?string $podHash,
        public readonly bool $restarted,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
