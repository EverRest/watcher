<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use InvalidArgumentException;

final class Usage
{
    public function __construct(
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly float $costUsd = 0.0,
    ) {
        if (min($inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens) < 0 || $costUsd < 0.0) {
            throw new InvalidArgumentException('Usage values cannot be negative');
        }
    }

    /** Model is kept when equal, otherwise "mixed". */
    public function plus(self $o): self
    {
        return new self(
            $this->model === $o->model ? $this->model : 'mixed',
            $this->inputTokens + $o->inputTokens,
            $this->outputTokens + $o->outputTokens,
            $this->cacheReadTokens + $o->cacheReadTokens,
            $this->cacheWriteTokens + $o->cacheWriteTokens,
            $this->costUsd + $o->costUsd,
        );
    }
}
