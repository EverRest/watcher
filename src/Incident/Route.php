<?php

declare(strict_types=1);

namespace Watcher\Incident;

use Watcher\Analyze\Severity;
use Watcher\Notify\Destination;

final class Route
{
    /** @param list<Severity>|null $severities */
    public function __construct(
        public readonly string $name,
        public readonly ?array $severities,
        public readonly ?Destination $destination,
    ) {
    }

    public function matches(Severity $severity): bool
    {
        return $this->severities !== null && in_array($severity, $this->severities, true);
    }
}
