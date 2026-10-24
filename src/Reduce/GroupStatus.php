<?php

declare(strict_types=1);

namespace Watcher\Reduce;

enum GroupStatus: string
{
    case New = 'new';
    case Grown = 'grown';
    case Unchanged = 'unchanged';
    case Expected = 'expected';

    /** `$seen`: the fingerprint appeared in an earlier compared window, so a zero value means rare, not new. */
    public static function resolve(int $count, int|float|null $previousCount, float $growthFactor, bool $seen = false): self
    {
        return match (true) {
            $previousCount === null || $previousCount == 0 => $seen ? self::Expected : self::New,
            $count >= $growthFactor * $previousCount => self::Grown,
            default => self::Unchanged,
        };
    }
}
