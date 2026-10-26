<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Throwable;
use Watcher\Mcp\DryRunGuard;
use Watcher\Mcp\DryRunPipeline;
use Watcher\Mcp\OutputGuard;
use Watcher\Mcp\Tool;
use Watcher\Mcp\ToolError;

/** FR-035/036: one dry run through {@see DryRunPipeline}, behind {@see DryRunGuard}. Only listed with `mcp.allow_dry_run`. */
final class DryRunTool implements Tool
{
    public function __construct(private readonly DryRunPipeline $pipeline, private readonly DryRunGuard $guard, private readonly OutputGuard $out)
    {
    }

    public function name(): string
    {
        return 'watcher_dry_run';
    }

    public function group(): string
    {
        return 'inspect';
    }

    public function description(): string
    {
        return 'Runs the full pipeline once without posting or storing anything. Makes real, billed model calls unless estimate_only is true. Requires confirm_cost=true and is refused when it would exceed the daily limit.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'confirm_cost' => ['type' => 'boolean', 'description' => 'must be true for a run that calls a model; real, billed tokens'],
            'estimate_only' => ['type' => 'boolean', 'default' => false],
        ]];
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function call(array $args): array
    {
        if (($args['estimate_only'] ?? false) === true) {
            if ($this->guard->busy()) {
                throw new ToolError('busy');
            }
            $steps = [];
            $total = 0.0;
            foreach ($this->pipeline->estimate() as $s) {
                $steps[] = ['step' => OutputGuard::enum($s['step'], ['triage', 'analyze', 'escalate']), 'groups' => OutputGuard::int($s['groups']), 'tokens_in' => OutputGuard::int($s['tokens_in']), 'tokens_out_max' => OutputGuard::int($s['tokens_out_max']), 'cost_usd' => OutputGuard::float($s['cost_usd'])];
                $total += $s['cost_usd'];
            }

            return ['estimate' => $steps, 'total_usd' => round($total, 6)];
        }
        $bound = $this->guard->admit($args['confirm_cost'] ?? null, $this->pipeline);
        $this->guard->enter($bound); // booked before the first model call: paid calls made before a failure must not look free
        $failed = true;
        try {
            $report = $this->pipeline->run();
            $failed = false;
        } catch (Throwable) {
            throw new ToolError('internal_error');
        } finally {
            $this->guard->end($failed);
        }

        return $this->report($report, $bound['usd']);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function report(array $r, float $bound): array
    {
        $c = is_array($r['counts'] ?? null) ? $r['counts'] : [];
        $t = is_array($r['tokens'] ?? null) ? $r['tokens'] : [];
        $findings = [];
        foreach (is_array($r['findings'] ?? null) ? $r['findings'] : [] as $f) {
            $f = is_array($f) ? $f : [];
            $findings[] = ['fp' => OutputGuard::fingerprint($f['fp'] ?? null), 'severity' => OutputGuard::severity($f['severity'] ?? null), 'title' => $this->out->text($f['title'] ?? null, 80)];
        }

        return [
            'available' => true,
            'window_start' => OutputGuard::timestamp($r['window_start'] ?? null),
            'window_end' => OutputGuard::timestamp($r['window_end'] ?? null),
            'at' => OutputGuard::timestamp($r['at'] ?? null),
            'outcome' => OutputGuard::outcome($r['outcome'] ?? null),
            'counts' => ['events' => OutputGuard::int($c['events'] ?? null), 'groups' => OutputGuard::int($c['groups'] ?? null), 'candidates' => OutputGuard::int($c['candidates'] ?? null), 'findings' => OutputGuard::int($c['findings'] ?? null)],
            'tokens' => ['in' => OutputGuard::int($t['in'] ?? null), 'out' => OutputGuard::int($t['out'] ?? null), 'cached' => OutputGuard::int($t['cached'] ?? null)],
            'cost_usd' => OutputGuard::float($r['cost_usd'] ?? null),
            'findings' => $findings,
            'dry_run' => true,
            'estimate_usd' => round($bound, 6),
        ];
    }
}
