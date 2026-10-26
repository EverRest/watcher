<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Watcher\Mcp\DocsIndex;
use Watcher\Mcp\Tool;

/** FR-082: lexical search over the package's own documentation. */
final class SearchDocsTool implements Tool
{
    public function __construct(private readonly DocsIndex $index)
    {
    }

    public function name(): string
    {
        return 'watcher_search_docs';
    }

    public function group(): string
    {
        return 'docs';
    }

    public function description(): string
    {
        return 'Searches the installed package documentation (plain text, no model, no network) and returns file, anchor, heading and a short snippet.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['query'], 'properties' => [
            'query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 200],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 5],
        ]];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        $r = $this->index->search((string) $args['query'], is_int($args['limit'] ?? null) ? $args['limit'] : 5);

        return $r === null ? ['available' => false, 'reason' => 'docs_not_installed'] : ['available' => true, 'results' => $r];
    }
}
