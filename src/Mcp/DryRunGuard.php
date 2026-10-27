<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/**
 * A dry run makes real model calls and writes nothing, so a loop of calls could bill again and again. Order: busy, then the
 * confirmation (strict boolean true), then refusal after a failed run. Admission and the busy flag are one step (single-threaded
 * process), so two calls in one server cannot both pass. After a run that failed once model calls may have been billed, so full
 * runs are refused until the process restarts. Every refusal makes zero model calls.
 */
final class DryRunGuard
{
    private bool $running = false;
    /** After a run that failed once model calls may have been made, no further full run is admitted until the process restarts (the operator's reset). */
    private bool $failed = false;

    /** @throws ToolError */
    public function admit(mixed $confirmModelCalls): void
    {
        if ($this->running) {
            throw new ToolError('busy');
        }
        if ($confirmModelCalls !== true) {
            throw new ToolError('model_calls_not_confirmed');
        }
        if ($this->failed) {
            throw new ToolError('previous_run_failed');
        }

        $this->running = true; // reserved here, in the same step as the checks: nothing can interleave in this process
    }

    /** @param bool $failed the run threw after it was admitted: further full runs are refused */
    public function end(bool $failed = false): void
    {
        $this->running = false;
        $this->failed = $this->failed || $failed;
    }

    public function busy(): bool
    {
        return $this->running;
    }
}
