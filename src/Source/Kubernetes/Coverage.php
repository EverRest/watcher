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
        /** @var array<string, int> entries dropped for lack of room, by severity class (normal, warning, critical) */
        public readonly array $overflowByClass = [],
    ) {
    }

    /** Which classes lost entries, in words: counts and fixed class names only. Null when nothing was dropped. */
    public function overflowText(): ?string
    {
        if ($this->overflow <= 0) {
            return null;
        }
        $parts = [];
        foreach (['critical', 'warning', 'normal'] as $class) {
            if (($this->overflowByClass[$class] ?? 0) > 0) {
                $parts[] = number_format($this->overflowByClass[$class]) . ' ' . $class;
            }
        }

        return 'ledger_overflow: ' . ($parts === [] ? number_format($this->overflow) . ' entries' : implode(', ', $parts)) . (($this->overflowByClass['critical'] ?? 0) > 0 ? '; critical events may be missing' : '; no critical event was dropped');
    }

    public function isComplete(): bool
    {
        return $this->gaps === [] && !$this->shortHistory && $this->unattributed === 0 && !$this->capped;
    }
}
