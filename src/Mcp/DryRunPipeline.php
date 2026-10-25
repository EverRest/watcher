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
     * @return list<array{step: string, groups: int, tokens_in: int, tokens_out_max: int, cost_usd: float}>
     */
    public function estimate(): array;

    /**
     * Upper bound of the escalation step: `escalation_cap` groups at the escalation model's worst-case price and worst-case tokens
     * (input and output). null when either is unknown (no price): the guard then refuses.
     *
     * @return array{usd: float, tokens: int}|null
     */
    public function escalationAllowance(): ?array;

    /**
     * A full dry run (real, billed model calls; nothing posted or stored).
     *
     * @return array<string, mixed> the `watcher_latest_report` shape: window_start, window_end, at, outcome, counts, tokens, cost_usd, findings
     */
    public function run(): array;
}
