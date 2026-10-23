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
        /** Display only: the id the API echoed (may be a dated form of the alias requested). Never used for pricing. */
        public readonly ?string $returnedModel = null,
    ) {
        if (min($inputTokens, $outputTokens, $cacheReadTokens, $cacheWriteTokens) < 0 || $costUsd < 0.0) {
            throw new InvalidArgumentException('Usage values cannot be negative');
        }
    }

    /** Model is kept when equal, otherwise "mixed". `model` is the id that was requested (the key of `analysis.prices`). */
    public function plus(self $o): self
    {
        return new self(
            $this->model === $o->model ? $this->model : 'mixed',
            $this->inputTokens + $o->inputTokens,
            $this->outputTokens + $o->outputTokens,
            $this->cacheReadTokens + $o->cacheReadTokens,
            $this->cacheWriteTokens + $o->cacheWriteTokens,
            $this->costUsd + $o->costUsd,
            $this->returnedModel === $o->returnedModel ? $this->returnedModel : null,
        );
    }
}
