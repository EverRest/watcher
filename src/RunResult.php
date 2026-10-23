<?php

declare(strict_types=1);

namespace Watcher;

final class RunResult
{
    public function __construct(
        public readonly RunOutcome $outcome,
        public readonly ?string $failureReason = null,
    ) {
    }

    public static function invalidConfig(string $reason): self
    {
        return new self(RunOutcome::InvalidConfig, $reason);
    }

    public function exitCode(): int
    {
        return match ($this->outcome) {
            RunOutcome::Failed => 1,
            RunOutcome::InvalidConfig => 2,
            default => 0,
        };
    }
}
