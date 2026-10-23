<?php

declare(strict_types=1);

namespace Watcher\Context;

use Watcher\Privacy\RedactedGroup;

/** FR-039: what a declared dependency looked like in the window. Holds redacted groups only. */
final class DependencyContext
{
    /**
     * @param array<string, int>       $totals
     * @param array<string, int>|null  $previousTotals
     * @param list<RedactedGroup>      $topErrors                 at most 5
     * @param list<int>                $referencedByTemplateIndexes
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $declared,
        public readonly array $totals,
        public readonly ?array $previousTotals,
        public readonly array $topErrors,
        public readonly array $referencedByTemplateIndexes,
        public readonly bool $firstSighting,
        public readonly bool $unavailable = false,
    ) {
    }

    /** Error-and-above events in the window. */
    public function errors(): int
    {
        return DependencyResolver::errors($this->totals);
    }

    /** Error-and-above events in the previous window; null when there is no previous snapshot. */
    public function previousErrors(): ?int
    {
        return $this->previousTotals === null ? null : DependencyResolver::errors($this->previousTotals);
    }

    /** @param list<RedactedGroup> $topErrors */
    public function withTopErrors(array $topErrors): self
    {
        return new self($this->name, $this->declared, $this->totals, $this->previousTotals, $topErrors, $this->referencedByTemplateIndexes, $this->firstSighting, $this->unavailable);
    }

    /** @param list<int> $indexes */
    public function withReferences(array $indexes): self
    {
        return new self($this->name, $this->declared, $this->totals, $this->previousTotals, $this->topErrors, $indexes, $this->firstSighting, $this->unavailable);
    }
}
