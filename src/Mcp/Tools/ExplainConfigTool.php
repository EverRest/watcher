<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Watcher\Config\KeyCatalog;
use Watcher\Mcp\Tool;

/** FR-083: what a configuration key is, from {@see KeyCatalog}. An unknown key is `found: false` with at most three close known keys; the input is not echoed. */
final class ExplainConfigTool implements Tool
{
    public function name(): string
    {
        return 'watcher_explain_config';
    }

    public function group(): string
    {
        return 'docs';
    }

    public function description(): string
    {
        return 'Explains one watcher configuration key: type, default, environment variable, whether it is a secret, constraints and the documentation anchor.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['key'], 'properties' => ['key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_.<>*-]{0,80}$']]];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        $key = (string) $args['key'];
        $e = KeyCatalog::find($key);
        if ($e === null) {
            return ['found' => false, 'did_you_mean' => KeyCatalog::suggest($key)];
        }

        return [
            'found' => true,
            'key' => $e['path'],
            'type' => $e['type'],
            'required' => $e['required'],
            'default' => $e['default'],
            'env' => $e['env'],
            'secret' => $e['secret'],
            'summary' => $e['summary'],
            'constraints' => $e['constraints'],
            'related' => $e['related'],
            'pitfalls' => $e['pitfalls'],
            'docs' => $e['docs'],
        ];
    }
}
