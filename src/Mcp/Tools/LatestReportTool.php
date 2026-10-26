<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Throwable;
use Watcher\Mcp\LastReport;
use Watcher\Mcp\OutputGuard;
use Watcher\Mcp\Tool;
use Watcher\Mcp\ToolError;

/** FR-032: the last reported (or quiet) window from the last-report record: window, outcome, counts, up to `limit` findings (fp, severity, title). */
final class LatestReportTool implements Tool
{
    public function __construct(private readonly LastReport $reports, private readonly OutputGuard $guard, private readonly bool $enabled)
    {
    }

    public function name(): string
    {
        return 'watcher_latest_report';
    }

    public function group(): string
    {
        return 'inspect';
    }

    public function description(): string
    {
        return 'Returns the most recent report window of this service: outcome, counts and up to `limit` findings with fingerprint, severity and a short title.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10]]];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        if (!$this->enabled) {
            return ['available' => false, 'reason' => 'disabled'];
        }
        try {
            $r = $this->reports->get();
        } catch (Throwable) {
            throw new ToolError('state_unavailable');
        }
        if ($r === null) {
            return ['available' => false, 'reason' => 'no_report'];
        }
        $limit = is_int($args['limit'] ?? null) ? $args['limit'] : 10;
        $findings = [];
        foreach (array_slice($r['findings'], 0, $limit) as $f) {
            $findings[] = ['fp' => OutputGuard::fingerprint($f['fp'] ?? null), 'severity' => OutputGuard::severity($f['severity'] ?? null), 'title' => $this->guard->text($f['title'] ?? null, 80)];
        }
        $c = $r['counts'];

        return [
            'available' => true,
            'window_start' => OutputGuard::timestamp($r['windowStart']),
            'window_end' => OutputGuard::timestamp($r['windowEnd']),
            'at' => OutputGuard::timestamp($r['at']),
            'outcome' => OutputGuard::outcome($r['outcome']),
            'counts' => ['events' => OutputGuard::int($c['events'] ?? null), 'groups' => OutputGuard::int($c['groups'] ?? null), 'candidates' => OutputGuard::int($c['candidates'] ?? null), 'findings' => OutputGuard::int($c['findings'] ?? null)],
            'findings' => $findings,
        ];
    }
}
