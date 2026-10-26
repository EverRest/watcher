<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Watcher\Mcp\Tool;
use Watcher\OutcomeRegistry;

/** FR-084: what an outcome, reason or code means, from the registry. No code lists every registered code (bounded, no prose). */
final class ExplainOutcomeTool implements Tool
{
    public function name(): string
    {
        return 'watcher_explain_outcome';
    }

    public function group(): string
    {
        return 'docs';
    }

    public function description(): string
    {
        return 'Explains a watcher outcome, reason or notice code: meaning, likely cause, what to check, how to fix, related codes and the documentation anchor.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => ['code' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,40}(:[a-z0-9_]{1,40})?$']]];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        if (!isset($args['code'])) {
            return ['codes' => array_values(array_map(static fn (array $e): array => ['code' => $e['code'], 'kind' => $e['kind'], 'severity' => $e['severity']], OutcomeRegistry::all()))];
        }
        $e = OutcomeRegistry::explain((string) $args['code']);
        if ($e === null) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'code' => (string) $args['code'],
            'kind' => $e['kind'],
            'severity' => $e['severity'],
            'exit_code' => $e['exit_code'],
            'meaning' => $e['meaning'],
            'likely_cause' => $e['likely_cause'],
            'check' => $e['check'],
            'fix' => $e['fix'],
            'related' => $e['related'],
            'docs' => $e['docs'],
            'reserved_by' => $e['reserved_by'],
        ];
    }
}
