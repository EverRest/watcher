<?php

declare(strict_types=1);

namespace Watcher\Report;

final class ReportRunResult
{
    public const OUTCOME_SENT = 'sent';
    public const OUTCOME_ALREADY = 'already_sent';
    public const OUTCOME_NO_RUNS = 'no_runs';
    public const OUTCOME_DISABLED = 'disabled';

    public function __construct(
        public readonly string $outcome,
        public readonly ?ReportData $data = null,
        public readonly ?string $text = null,
    ) {
    }

    public function exitCode(): int
    {
        return match ($this->outcome) {
            self::OUTCOME_SENT, self::OUTCOME_ALREADY, self::OUTCOME_NO_RUNS, self::OUTCOME_DISABLED => 0,
            default => 1,
        };
    }
}
