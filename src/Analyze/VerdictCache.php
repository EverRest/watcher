<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Privacy\RedactedGroup;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/** Per-service triage verdicts so a settled group is not sent to a model again (FR-018). */
final class VerdictCache
{
    public function __construct(
        private readonly StateStore $store,
        private readonly string $service,
        private readonly int $retention,
    ) {
    }

    /** @return array{triage: string, atCount: int, finding?: array<string, mixed>}|null */
    public function get(string $fingerprint): ?array
    {
        /** @var array{triage: string, atCount: int, finding?: array<string, mixed>}|null */
        return $this->store->get(Keys::verdict($this->service, $fingerprint));
    }

    public function put(string $fingerprint, string $verdict, int $atCount, ?Finding $finding): void
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
            ];
        }
        $this->store->put(Keys::verdict($this->service, $fingerprint), $value, $this->retention);
    }

    public function isReusable(RedactedGroup $g, float $growthFactor): bool
    {
        $v = $this->get($g->fingerprint);

        return $v !== null && $g->count < $growthFactor * $v['atCount'];
    }
}
