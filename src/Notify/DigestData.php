<?php

declare(strict_types=1);

namespace Watcher\Notify;

use DateTimeImmutable;
use Watcher\Analyze\Finding;
use Watcher\Analyze\Usage;
use Watcher\Privacy\RedactedGroup;

/** Everything DigestRenderer needs; all strings are already redacted or bounded. */
final class DigestData
{
    /**
     * @param list<Finding>                $findings
     * @param array<string, RedactedGroup> $groups  by fingerprint (count and trend)
     * @param array<string, string>        $models  step => model id, for the footer
     */
    public function __construct(
        public readonly string $service,
        public readonly string $environment,
        public readonly DateTimeImmutable $windowStart,
        public readonly DateTimeImmutable $windowEnd,
        public readonly string $timezone,
        public readonly array $findings,
        public readonly array $groups,
        public readonly ?string $release = null,
        public readonly ?Usage $usage = null,
        public readonly array $models = [],
        public readonly float $costUsd = 0.0,
        public readonly ?string $levelAnomalyLine = null,
        public readonly ?int $eventsReadTruncated = null,
        public readonly int $droppedGroups = 0,
        public readonly int $otherGroups = 0,
        public readonly int $otherEvents = 0,
        public readonly int $muted = 0,
    ) {
    }
}
