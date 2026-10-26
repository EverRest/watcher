<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Watcher\Mcp\RolloutInspector;
use Watcher\Mcp\Tool;

final class RolloutCheckTool implements Tool
{
    public function __construct(private readonly RolloutInspector $inspector)
    {
    }

    public function name(): string
    {
        return 'watcher_rollout_check';
    }

    public function group(): string
    {
        return 'onboarding';
    }

    public function description(): string
    {
        return 'Checks the local installation: PHP version, extensions for the chosen state store, the Composer constraint, the configuration and the master switch. With connect=true it reads once from the configured state store.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024, 'x-path' => true],
            'connect' => ['type' => 'boolean', 'default' => false, 'description' => 'true opens one read-only connection to the configured state store; only for a configuration inside an allowed root'],
        ]];
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function call(array $args): array
    {
        return $this->inspector->inspect(isset($args['path']) ? (string) $args['path'] : null, ($args['connect'] ?? false) === true);
    }
}
