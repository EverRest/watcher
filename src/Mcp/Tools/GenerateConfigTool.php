<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Watcher\Mcp\ConfigGenerator;
use Watcher\Mcp\Tool;

final class GenerateConfigTool implements Tool
{
    public function __construct(private readonly ConfigGenerator $generator)
    {
    }

    public function name(): string
    {
        return 'watcher_generate_config';
    }

    public function group(): string
    {
        return 'onboarding';
    }

    public function description(): string
    {
        return 'Drafts a PHP configuration file from non-secret answers. Every credential is read from an environment variable; the answer lists the variable names and holds no value. Nothing is written to disk.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['service', 'run_at', 'host', 'source', 'notifier', 'state'], 'properties' => [
            'service' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._@-]{0,63}$'],
            'environment' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$'],
            'run_at' => ['type' => 'string', 'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$'],
            'timezone' => ['type' => 'string', 'pattern' => '^[A-Za-z_]+(/[A-Za-z0-9_+-]+){0,2}$', 'default' => 'UTC'],
            'host' => SchemaDefs::host(), 'source' => SchemaDefs::source(), 'notifier' => SchemaDefs::notifier(), 'state' => SchemaDefs::state(),
            'stream_tags' => ['type' => 'object', 'maxProperties' => 8, 'propertyNames' => ['pattern' => '^[a-zA-Z_][a-zA-Z0-9_]{0,62}$'], 'additionalProperties' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._-]{1,63}$']],
            'level_mode' => ['enum' => ['text', 'label', 'all'], 'default' => 'text'],
            'daily_budget_usd' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'default' => 0.5],
            'env_names' => ['type' => 'object', 'maxProperties' => 24, 'propertyNames' => ['pattern' => '^[a-z_]{2,32}$'], 'additionalProperties' => ['type' => 'string', 'pattern' => '^WATCHER_[A-Z0-9_]{1,56}$']],
        ]];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        /** @var array{service: string, environment?: string, run_at: string, timezone?: string, host: string, source: string, notifier: string, state: string, stream_tags?: array<string, string>, level_mode?: string, daily_budget_usd?: int|float, env_names?: array<string, string>} $args */
        return $this->generator->generate($args);
    }
}
