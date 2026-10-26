<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Level;
use Watcher\Privacy\RedactedGroup;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/** Per-service triage verdicts so a settled group is not sent to a model again (FR-018). */
final class VerdictCache
{
    private const MAX_AT_COUNT = 10_000_000;

    public function __construct(
        private readonly StateStore $store,
        private readonly string $service,
        private readonly int $retention,
        private readonly int $settledTtl = 3 * 86400,
    ) {
    }

    /** @return array{triage: string, atCount: int, finding?: array<string, mixed>}|null */
    public function get(string $fingerprint): ?array
    {
        $v = $this->store->get(Keys::verdict($this->service, $fingerprint));
        // The store may be shared: a value of the wrong shape is "no verdict", never trusted or thrown on.
        if ($v === null
            || !is_int($v['atCount'] ?? null) || $v['atCount'] < 0 || $v['atCount'] >= self::MAX_AT_COUNT
            || !in_array($v['triage'] ?? null, ['noise', 'known', 'interesting'], true)) {
            return null;
        }

        /** @var array{triage: string, atCount: int, finding?: array<string, mixed>} $v */
        return $v;
    }

    /**
     * A noise/known verdict is attacker-influenced (log text steers the model), so it is never kept for a group
     * that is error or higher or matched a watch rule, and for the rest only for the short TTL.
     */
    public function storeTriage(RedactedGroup $g, string $verdict): void
    {
        if ($verdict !== 'interesting' && self::mustNotBeSilenced($g)) {
            return;
        }
        $this->put($g->fingerprint, $verdict, $g->count, null, $verdict === 'interesting' ? null : $this->settledTtl);
    }

    public static function mustNotBeSilenced(RedactedGroup $g): bool
    {
        return $g->level->value >= Level::Error->value || $g->watchMatches !== [];
    }

    /** The lowest severity a finding for this group may carry: the level the logs show, or warn for a watch-rule group. Null when neither applies. */
    public static function severityFloor(RedactedGroup $g): ?Severity
    {
        return match (true) {
            $g->level->value >= Level::Critical->value => Severity::Critical,
            $g->level->value >= Level::Error->value => Severity::Error,
            $g->watchMatches !== [] => Severity::Warn,
            default => null,
        };
    }

    /** "Nothing to see": the model's own view of a finding is noise or info. */
    public static function isWeak(Finding $f): bool
    {
        return $f->kind === FindingKind::Noise || $f->severity === Severity::Info;
    }

    public function put(string $fingerprint, string $verdict, int $atCount, ?Finding $finding, ?int $ttl = null): void
    {
        $value = ['triage' => $verdict, 'atCount' => $atCount];
        if ($finding !== null) {
            $value['finding'] = [
                'fingerprints' => $finding->fingerprints,
                'kind' => $finding->kind->value,
                'severity' => $finding->severity->value,
                'title' => $finding->title,
                'evidence' => $finding->evidence,
                'suspected_cause' => $finding->suspectedCause,
                'suggested_action' => $finding->suggestedAction,
                'confidence' => $finding->confidence,
                'escalated' => $finding->escalated,
                'how_to_check' => $finding->howToCheck,
                'related_checks' => $finding->relatedChecks,
            ];
        }
        $this->store->put(Keys::verdict($this->service, $fingerprint), $value, $ttl ?? $this->retention);
    }

    public function isReusable(RedactedGroup $g, float $growthFactor): bool
    {
        $v = $this->get($g->fingerprint);

        if ($v === null || ($v['triage'] !== 'interesting' && self::mustNotBeSilenced($g))) {
            return false;
        }
        // The analysis path is the same trust problem as triage: for an error-or-higher / watch-rule group, "analysed, nothing to report"
        // (or only noise/info) must never settle, also not when an older version of the package stored it.
        $floor = self::severityFloor($g);
        $severity = Severity::tryFrom((string) ($v['finding']['severity'] ?? ''));
        if (self::mustNotBeSilenced($g) && (!isset($v['finding']) || ($v['finding']['kind'] ?? null) === FindingKind::Noise->value
            || $severity === null || ($floor !== null && $severity->rank() < $floor->rank()))) {
            return false;
        }

        return $g->count < $growthFactor * $v['atCount'];
    }
}
