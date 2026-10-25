<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use DateTimeImmutable;

/**
 * What the digest must say about pod events it may have missed (research R10): integers and times only.
 *
 * @param list<array{DateTimeImmutable, DateTimeImmutable}> $gaps periods without a successful read longer than the cluster's event retention
 */
final class Coverage
{
    /** @param list<array{DateTimeImmutable, DateTimeImmutable}> $gaps */
    public function __construct(
        public readonly array $gaps,
        public readonly ?DateTimeImmutable $since,
        public readonly bool $shortHistory,
        public readonly int $unattributed,
        public readonly bool $capped,
        public readonly int $overflow = 0,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->gaps === [] && !$this->shortHistory && $this->unattributed === 0 && !$this->capped;
    }
}
