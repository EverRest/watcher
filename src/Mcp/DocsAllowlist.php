<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/** FR-080: the only files the server serves, by exact relative path. All ship in the Composer archive (see .gitattributes; a test checks it). */
final class DocsAllowlist
{
    public const FILES = [
        'README.md' => 'text/markdown',
        'CHANGELOG.md' => 'text/markdown',
        'SECURITY.md' => 'text/markdown',
        'docs/README.md' => 'text/markdown',
        'docs/configuration.md' => 'text/markdown',
        'docs/operations.md' => 'text/markdown',
        'docs/privacy.md' => 'text/markdown',
        'docs/observability.md' => 'text/markdown',
        'docs/smoke-test.md' => 'text/markdown',
        'docs/mcp.md' => 'text/markdown',
        'docs/examples/orders-api.loki.config.php' => 'text/x-php',
        'docs/examples/smoke.config.php' => 'text/x-php',
        'docs/examples/watcher-fleet.php' => 'text/x-php',
        'docs/schema/watcher.event.v2.schema.json' => 'application/json',
        'config/watcher.example.php' => 'text/x-php',
        'config/watcher.php' => 'text/x-php',
    ];

    public const SCHEME = 'watcher-docs://';
    public const MAX_BYTES = 524288;
}
