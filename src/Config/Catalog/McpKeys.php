<?php

declare(strict_types=1);

namespace Watcher\Config\Catalog;

use Watcher\Config\KeyCatalog;

/**
 * Catalog entries for the MCP server: mcp.* (the groups served, dry run, the artisan path for the actions group).
 * The `mcp` section is read by McpSettings; these entries make the keys known to the catalog and the docs.
 *
 * Entries are merged, in this order, into {@see KeyCatalog}; see its docblock for the fields.
 *
 * @phpstan-import-type Entry from KeyCatalog
 */
final class McpKeys
{
    /** @var array<string, Entry> */
    public const ENTRIES = [
        'mcp.groups' => ['path' => 'mcp.groups', 'type' => 'list', 'required' => false, 'default' => 'inspect,onboarding,docs', 'env' => 'WATCHER_MCP_GROUPS', 'secret' => false, 'summary' => 'Groups the MCP server serves: inspect, onboarding, docs, actions. actions is off unless named.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'mcp-optional-read-only-by-default']],
        'mcp.allow_dry_run' => ['path' => 'mcp.allow_dry_run', 'type' => 'bool', 'required' => false, 'default' => 'false', 'env' => 'WATCHER_MCP_ALLOW_DRY_RUN', 'secret' => false, 'summary' => 'Adds the watcher_dry_run tool.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'mcp-optional-read-only-by-default']],
        'mcp.artisan' => ['path' => 'mcp.artisan', 'type' => 'string', 'required' => false, 'default' => null, 'env' => 'WATCHER_MCP_ARTISAN', 'secret' => false, 'summary' => 'Absolute path to the artisan file. Required when the actions group is on; checked at startup.', 'docs' => ['file' => 'docs/configuration.md', 'anchor' => 'mcp-optional-read-only-by-default']],
    ];
}
