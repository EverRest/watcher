<?php

declare(strict_types=1);

namespace Watcher\Reduce;

enum GroupStatus: string
{
    case New = 'new';
    case Grown = 'grown';
    case Unchanged = 'unchanged';

    public static function resolve(int $count, ?int $previousCount, float $growthFactor): self
    {
        return match (true) {
            $previousCount === null || $previousCount === 0 => self::New,
            $count >= $growthFactor * $previousCount => self::Grown,
            default => self::Unchanged,
        };
    }
}
