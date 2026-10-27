<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Watcher\Mcp\SetupPlanner;
use Watcher\Mcp\Tool;

final class FirstDayTool implements Tool
{
    public function __construct(private readonly SetupPlanner $planner)
    {
    }

    public function name(): string
    {
        return 'watcher_first_day';
    }

    public function group(): string
    {
        return 'onboarding';
    }

    public function description(): string
    {
        return 'Returns the first-day procedure for a host: check, estimate, baseline run, second run, idempotency run and status, each with the command and the outcome code to expect.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['host'], 'properties' => ['host' => SchemaDefs::host()]];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        return $this->planner->firstDay((string) $args['host']);
    }
}
