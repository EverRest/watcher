<?php

declare(strict_types=1);

namespace Watcher\Reduce;

use DateTimeImmutable;
use Watcher\Level;

/**
 * Reducer output. `template` is null when the message is withheld (low volume, FR-044).
 * ponytail: no per-group sample events are kept; nothing downstream needs raw events.
 */
final class ProblemGroup
{
    /**
     * @param list<string> $frames       at most 5
     * @param list<string> $watchMatches
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly ?string $template,
        public readonly Level $level,
        public readonly ?string $errorType,
        public readonly array $frames,
        public readonly int $count,
        public readonly DateTimeImmutable $firstSeen,
        public readonly DateTimeImmutable $lastSeen,
        public readonly ?int $previousCount = null,
        public readonly GroupStatus $status = GroupStatus::New,
        public readonly array $watchMatches = [],
    ) {
    }

    public function withPrevious(?int $previousCount, float $growthFactor): self
    {
        return new self(
            $this->fingerprint,
            $this->template,
            $this->level,
            $this->errorType,
            $this->frames,
            $this->count,
            $this->firstSeen,
            $this->lastSeen,
            $previousCount,
            GroupStatus::resolve($this->count, $previousCount, $growthFactor),
            $this->watchMatches,
        );
    }
}
