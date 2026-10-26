<?php

declare(strict_types=1);

namespace Watcher\Incident;

final class KnownIssue
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $ticket,
        public readonly bool $resolved,
        public readonly ?string $until,
    ) {
    }

    public function matches(string $fingerprint): bool
    {
        return str_starts_with($fingerprint, $this->fingerprint);
    }

    public function activeOn(string $utcDate): bool
    {
        return $this->until === null || $this->until >= $utcDate;
    }
}
