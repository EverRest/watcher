<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Closure;
use Watcher\Mcp\ArtisanCommand;
use Watcher\Mcp\Tool;
use Watcher\Mcp\ToolError;

/**
 * The `actions` group: the watcher's own artisan commands with their parameters, so a run from MCP is the same run as
 * on the console. Argument values are checked by the schema before a command is built; no value reaches a shell.
 */
final class WatcherActions
{
    /** Seconds between two runs of one action in one MCP session. */
    public const MIN_INTERVAL = 300;

    private const ISO = '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$';
    private const ONLY = '^[A-Za-z0-9._,-]{1,200}$';

    /**
     * @param Closure(string, array<string, mixed>): void $audit
     * @param Closure(): int $now unix seconds
     * @return list<Tool>
     */
    public static function tools(ArtisanCommand $artisan, Closure $audit, Closure $now): array
    {
        return [
            self::tool('watcher_run_digest', 'Runs the digest now for this deployment. fleet=true (default) runs every service with `watcher:fleet`; `only` picks services. dry_run=true (default) posts nothing and records nothing; dry_run=false sends and records, and needs confirm=true. `now` runs as that ISO-8601 instant.', [
                'fleet' => ['type' => 'boolean', 'default' => true],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'now' => ['type' => 'string', 'pattern' => self::ISO],
                'only' => ['type' => 'string', 'pattern' => self::ONLY],
            ], $artisan, $audit, $now),
            self::tool('watcher_health_check', 'Runs the health check now: reads the m-seq snapshot, stores it, and sends version or up/down alerts when they changed. Needs confirm=true.', [], $artisan, $audit, $now),
            self::tool('watcher_test_message', 'Posts one labelled test message to each notify destination (`watcher:check --post-test`). Needs confirm=true.', [], $artisan, $audit, $now),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: string, 1: list<string>} the artisan command and its flags
     *
     * @throws ToolError
     */
    public static function invocation(string $tool, array $args): array
    {
        return match ($tool) {
            'watcher_run_digest' => self::digest($args),
            'watcher_health_check' => ['watcher:health', []],
            'watcher_test_message' => ['watcher:check', ['--post-test', '--json']],
            default => throw new ToolError('unknown_action'),
        };
    }

    /** @param array<string, mixed> $args */
    public static function needsConfirm(string $tool, array $args): bool
    {
        return match ($tool) {
            'watcher_run_digest' => ($args['dry_run'] ?? true) !== true,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: string, 1: list<string>}
     */
    private static function digest(array $args): array
    {
        $fleet = ($args['fleet'] ?? true) === true;
        $flags = [];
        if (($args['dry_run'] ?? true) === true) {
            $flags[] = '--dry-run';
        }
        $flags[] = '--json';
        if (is_string($args['now'] ?? null)) {
            // A real run recorded at a chosen instant would move the state's window: `now` is for previews only.
            if (($args['dry_run'] ?? true) !== true) {
                throw new ToolError('now_needs_dry_run');
            }
            $flags[] = '--now=' . $args['now'];
        }
        if ($fleet && is_string($args['only'] ?? null)) {
            $flags[] = '--only=' . $args['only'];
        }

        return [$fleet ? 'watcher:fleet' : 'watcher:run', $flags];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     */
    private static function tool(string $name, string $description, array $properties, ArtisanCommand $artisan, Closure $audit, Closure $now): ActionTool
    {
        $properties['confirm'] = ['type' => 'boolean'];
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties];

        return new ActionTool(
            name: $name,
            description: $description,
            inputSchema: $schema,
            run: static function (array $args) use ($name, $artisan): array {
                [$command, $flags] = self::invocation($name, $args);

                return $artisan->run($command, $flags);
            },
            audit: $audit,
            needsConfirm: static fn (array $args): bool => self::needsConfirm($name, $args),
            minIntervalSeconds: self::MIN_INTERVAL,
            now: $now,
        );
    }
}
