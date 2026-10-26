<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Watcher\Config\Env;
use Watcher\Config\InvalidConfig;

/**
 * The `mcp` section (FR-040/095): `allow_dry_run` (strict boolean, default false, `WATCHER_MCP_ALLOW_DRY_RUN`) and `groups`
 * (default all three, `WATCHER_MCP_GROUPS` comma list). Any other key is rejected naming `mcp.<key>`.
 *
 * INTEGRATOR (T018/T051): move this into `WatchConfig`/`KeyCatalog`; until then {@see McpCommand} strips the `mcp` key before `WatchConfig::fromArray`
 * (which would reject it as unknown) and reads it here. `watcher check` therefore still rejects a config file that holds an `mcp` key.
 */
final class McpSettings
{
    public const GROUPS = ['inspect', 'onboarding', 'docs'];

    /** @param list<string> $groups */
    public function __construct(public readonly bool $allowDryRun, public readonly array $groups)
    {
    }

    /**
     * @param array<mixed> $section
     * @throws InvalidConfig
     */
    public static function fromArray(array $section): self
    {
        foreach (array_keys($section) as $k) {
            if (!in_array($k, ['allow_dry_run', 'groups'], true)) {
                throw new InvalidConfig('Unknown configuration key: mcp.' . (is_string($k) ? $k : (string) $k));
            }
        }
        $allow = $section['allow_dry_run'] ?? Env::bool('WATCHER_MCP_ALLOW_DRY_RUN', false);
        if (!is_bool($allow)) {
            throw new InvalidConfig('mcp.allow_dry_run must be true or false');
        }
        $groups = $section['groups'] ?? Env::list('WATCHER_MCP_GROUPS', self::GROUPS);
        if (!is_array($groups) || !array_is_list($groups) || $groups === [] || count(array_unique($groups)) !== count($groups) || array_diff($groups, self::GROUPS) !== []) {
            throw new InvalidConfig('mcp.groups must be a non-empty list of unique names from: ' . implode(', ', self::GROUPS));
        }

        return new self($allow, array_map('strval', $groups));
    }
}
