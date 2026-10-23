<?php

declare(strict_types=1);

namespace Watcher;

use Watcher\Analyze\Usage;

final class RunResult
{
    public function __construct(
        public readonly RunOutcome $outcome,
        public readonly ?string $failureReason = null,
        public readonly ?Usage $usage = null,
    ) {
    }

    public static function invalidConfig(string $reason): self
    {
        return new self(RunOutcome::InvalidConfig, $reason);
    }

    /**
     * One line for an operator: outcome, the (short, secret-free) failure reason, tokens and cost when a model ran.
     * No line breaks and no double quotes, so it cannot be split or forged in a log.
     */
    public function summary(): string
    {
        $line = 'outcome=' . $this->outcome->value;
        if ($this->failureReason !== null && $this->failureReason !== '') {
            $reason = (string) preg_replace('/\s+/u', ' ', str_replace('"', "'", $this->failureReason));
            $line .= ' reason="' . trim($reason) . '"';
        }
        if ($this->usage !== null) {
            $line .= sprintf(
                ' tokens_in=%d tokens_out=%d tokens_cached=%d cost_usd=%s',
                $this->usage->inputTokens,
                $this->usage->outputTokens,
                $this->usage->cacheReadTokens,
                rtrim(rtrim(number_format($this->usage->costUsd, 4, '.', ''), '0'), '.') ?: '0',
            );
        }

        return $line;
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
