<?php

declare(strict_types=1);

namespace Watcher\Mcp\Tools;

use Throwable;
use Watcher\Analyze\VerdictCache;
use Watcher\Mcp\OutputGuard;
use Watcher\Mcp\Tool;
use Watcher\Mcp\ToolError;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * FR-033: the stored finding for a fingerprint, field by field from the allowlist of data-model.md (the masked template, frames and
 * everything else in the record are never read out). The mute suggestion is built here from a fixed sentence and the fingerprint.
 */
final class FindingTool implements Tool
{
    /** @param list<string> $dependencyNames the configured dependency names (a `related_checks` entry must be a fingerprint or one of these) */
    public function __construct(
        private readonly StateStore $store,
        private readonly VerdictCache $verdicts,
        private readonly string $service,
        private readonly array $dependencyNames,
        private readonly OutputGuard $guard,
        private readonly bool $enabled,
    ) {
    }

    public function name(): string
    {
        return 'watcher_finding';
    }

    public function group(): string
    {
        return 'inspect';
    }

    public function description(): string
    {
        return 'Returns the stored finding for one 12-character fingerprint: severity, kind, title, evidence, suspected cause, suggested action and how to check.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['fp'], 'properties' => ['fp' => ['type' => 'string', 'pattern' => '^[0-9a-f]{12}$']]];
    }

    public function openWorld(): bool
    {
        return false;
    }

    public function call(array $args): array
    {
        if (!$this->enabled) {
            return ['found' => false, 'reason' => 'disabled'];
        }
        $fp = OutputGuard::fingerprint($args['fp'] ?? null);
        if ($fp === null) {
            return ['found' => false]; // unreachable after schema validation; never reaches the store
        }
        try {
            $v = $this->verdicts->get($fp);
            $reported = $this->store->get(Keys::reported($this->service, $fp));
        } catch (Throwable) {
            throw new ToolError('state_unavailable');
        }
        $f = $v['finding'] ?? null;
        if ($v === null || !is_array($f)) {
            return ['found' => false]; // never seen, expired and "settled without a finding" look the same
        }

        return [
            'found' => true,
            'fp' => $fp,
            'severity' => OutputGuard::severity($f['severity'] ?? null),
            'kind' => OutputGuard::kind($f['kind'] ?? null),
            'confidence' => OutputGuard::float($f['confidence'] ?? null, 0.0, 1.0),
            'escalated' => is_bool($f['escalated'] ?? null) ? $f['escalated'] : null,
            'title' => $this->guard->text($f['title'] ?? null, 80),
            'evidence' => $this->guard->text($f['evidence'] ?? null, 240),
            'suspected_cause' => $this->guard->text($f['suspected_cause'] ?? null, 240),
            'suggested_action' => $this->guard->text($f['suggested_action'] ?? null, 200),
            'how_to_check' => $this->guard->text($f['how_to_check'] ?? null, 200),
            'related_checks' => $this->related($f['related_checks'] ?? null),
            'last_reported_at' => OutputGuard::timestamp($reported['lastReportedAt'] ?? null),
            'count' => OutputGuard::int($reported['atCount'] ?? $v['atCount']),
            'mute_suggestion' => 'To silence this group, a human can add fingerprint ' . $fp . ' to "mute" in the service configuration. This server cannot do it.',
        ];
    }

    /** @return list<string> */
    private function related(mixed $v): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $r) {
            if (is_string($r) && (OutputGuard::fingerprint($r) !== null || in_array($r, $this->dependencyNames, true))) {
                $out[] = $r;
            }
            if (count($out) === 3) {
                break;
            }
        }

        return $out;
    }
}
