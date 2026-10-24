<?php

declare(strict_types=1);

namespace Watcher\Analyze;

/** What the analyzer needs from one model call, whatever client made it. */
final class ModelReply
{
    public function __construct(
        /** The first text block, or null when the reply had none. */
        public readonly ?string $text,
        public readonly ?string $stopReason,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {
    }
}
