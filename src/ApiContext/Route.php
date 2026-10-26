<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

/** An HTTP route found in a masked template. `unmatchable` = a placeholder hides part of it, so no guess is made. */
final class Route
{
    /** @param list<Segment> $segments */
    public function __construct(
        public readonly ?string $method,
        public readonly array $segments,
        public readonly bool $unmatchable = false,
    ) {
    }
}
