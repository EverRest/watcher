<?php

declare(strict_types=1);

namespace Watcher\Analyze;

final class CostCalculator
{
    /** @param array<string, array<int, int|float>> $prices model => [input, output, cache_read, cache_write] USD per million tokens */
    public function __construct(private readonly array $prices)
    {
    }

    public function cost(Usage $u): float
    {
        $p = $this->prices[$u->model] ?? throw new UnknownModelPrice('No price configured for a used model');

        return ($u->inputTokens * $p[0] + $u->outputTokens * $p[1]
            + $u->cacheReadTokens * $p[2] + $u->cacheWriteTokens * $p[3]) / 1_000_000;
    }
}
