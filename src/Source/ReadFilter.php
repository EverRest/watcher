<?php

declare(strict_types=1);

namespace Watcher\Source;

use InvalidArgumentException;
use Watcher\Level;

final class ReadFilter
{
    /**
     * @param list<string> $watchPatterns regex strings usable for a server-side filter
     */
    public function __construct(
        public readonly Level $minLevel,
        public readonly array $watchPatterns,
        public readonly int $eventCap,
    ) {
        if ($eventCap < 1) {
            throw new InvalidArgumentException('eventCap must be at least 1.');
        }
    }
}
