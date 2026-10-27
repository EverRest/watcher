<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/**
 * What `watcher_dry_run` needs from the pipeline; the real adapter runs `Watcher` with `RunOptions::dryRun()` and a discarding output
 * callable and reads the figures from the run result (INTEGRATOR, after T021). Nothing here prints.
 */
interface DryRunPipeline
{
    /**
     * The estimate path (`--dry-run --estimate`): no model call.
     *
     * @return list<array{step: string, groups: int}>
     */
    public function estimate(): array;

    /**
     * A full dry run (real, billed model calls; nothing posted or stored).
     *
     * @return array<string, mixed> the `watcher_latest_report` shape: window_start, window_end, at, outcome, counts, findings
     */
    public function run(): array;
}
