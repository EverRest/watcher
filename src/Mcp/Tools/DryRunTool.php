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
        return 'Runs the full pipeline once without posting or storing anything. Makes real, billed model calls unless estimate_only is true. Requires confirm_model_calls=true.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'confirm_model_calls' => ['type' => 'boolean', 'description' => 'must be true for a run that calls a model'],
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
            foreach ($this->pipeline->estimate() as $s) {
                $steps[] = ['step' => OutputGuard::enum($s['step'], ['triage', 'analyze', 'escalate']), 'groups' => OutputGuard::int($s['groups'])];
            }

            return ['estimate' => $steps];
        }
        $this->guard->admit($args['confirm_model_calls'] ?? null);
        $failed = true;
        try {
            $report = $this->pipeline->run();
            $failed = false;
        } catch (Throwable) {
            throw new ToolError('internal_error');
        } finally {
            $this->guard->end($failed);
        }

        return $this->report($report);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function report(array $r): array
    {
        $c = is_array($r['counts'] ?? null) ? $r['counts'] : [];
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
            'findings' => $findings,
            'dry_run' => true,
        ];
    }
}
