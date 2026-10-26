<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Watcher\Mcp\SetupPlanner;
use Watcher\Mcp\Tool;

final class SetupPlanTool implements Tool
{
    public function __construct(private readonly SetupPlanner $planner)
    {
    }

    public function name(): string
    {
        return 'watcher_setup_plan';
    }

    public function group(): string
    {
        return 'onboarding';
    }

    public function description(): string
    {
        return 'Returns the ordered setup plan for a host, log source, notifier and state store: what to collect, where to find it, which environment variables to define, how to verify each step and how to roll back.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['host', 'source', 'notifier', 'state'], 'properties' => [
            'host' => SchemaDefs::host(), 'source' => SchemaDefs::source(), 'notifier' => SchemaDefs::notifier(), 'state' => SchemaDefs::state(),
            'features' => ['type' => 'array', 'uniqueItems' => true, 'maxItems' => 6, 'items' => ['enum' => ['dependencies', 'links', 'observe_sinks', 'weekly_summary', 'fleet', 'dashboard_only']]],
        ]];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        /** @var list<string> $features */
        $features = is_array($args['features'] ?? null) ? array_values($args['features']) : [];

        return $this->planner->plan((string) $args['host'], (string) $args['source'], (string) $args['notifier'], (string) $args['state'], $features);
    }
}
