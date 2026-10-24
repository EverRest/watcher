<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\RunOutcome;
use Watcher\RunResult;

/** What one fleet entry ended as: a RunResult, or a fleet-level outcome (skipped_time, failed by exception). */
final class ServiceResult
{
    public const SKIPPED_TIME = 'skipped_time';

    private function __construct(
        public readonly string $service,
        /** A RunOutcome value or SKIPPED_TIME */
        public readonly string $outcome,
        public readonly ?string $reason,
        public readonly ?RunResult $result,
    ) {
    }

    public static function ran(string $service, RunResult $result): self
    {
        return new self($service, $result->outcome->value, $result->failureReason, $result);
    }

    public static function skippedTime(string $service): self
    {
        return new self($service, self::SKIPPED_TIME, null, null);
    }

    /** A throwable escaped the run: class name only (Principle I). */
    public static function crashed(string $service, \Throwable $e): self
    {
        return new self($service, RunOutcome::Failed->value, 'internal error (' . $e::class . ')', null);
    }

    public function exitCode(): int
    {
        return match ($this->outcome) {
            RunOutcome::Failed->value, self::SKIPPED_TIME => 1,
            RunOutcome::InvalidConfig->value => 2,
            default => 0,
        };
    }
}
