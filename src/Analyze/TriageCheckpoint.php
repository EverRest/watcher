<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Privacy\RedactedGroup;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * Triage verdicts of one window, written straight to the store (not the delivery buffer) when analysis fails after paid triage,
 * so the retry in the same window does not pay for triage again. Verdicts only, never findings: nothing here can look like a reported finding.
 * Same silencing rule as VerdictCache: noise/known is never kept for an error-or-higher or watch-rule group.
 */
final class TriageCheckpoint
{
    private const TTL = 6 * 3600;

    public function __construct(
        private readonly StateStore $store,
        private readonly string $service,
        private readonly string $windowKey,
    ) {
    }

    /**
     * @param list<RedactedGroup> $groups
     *
     * @return array<string, string> fingerprint => verdict, for the groups given only
     */
    public function load(array $groups): array
    {
        $saved = $this->store->get(Keys::triage($this->service, $this->windowKey)) ?? [];
        $out = [];
        foreach ($groups as $g) {
            $v = $saved[$g->fingerprint] ?? null;
            if (is_string($v) && self::keeps($g, $v)) {
                $out[$g->fingerprint] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, string> $verdicts
     * @param list<RedactedGroup>      $groups
     */
    public function save(array $verdicts, array $groups): void
    {
        $keep = [];
        foreach ($groups as $g) {
            $v = $verdicts[$g->fingerprint] ?? null;
            if ($v !== null && self::keeps($g, $v)) {
                $keep[$g->fingerprint] = $v;
            }
        }
        if ($keep !== []) {
            $this->store->put(Keys::triage($this->service, $this->windowKey), $keep, self::TTL);
        }
    }

    /** No delete in StateStore: an empty record that expires at once. */
    public function clear(): void
    {
        if ($this->store->get(Keys::triage($this->service, $this->windowKey)) !== null) {
            $this->store->put(Keys::triage($this->service, $this->windowKey), [], 1);
        }
    }

    private static function keeps(RedactedGroup $g, string $verdict): bool
    {
        return match ($verdict) {
            'interesting' => true,
            'noise', 'known' => !VerdictCache::mustNotBeSilenced($g),
            default => false,
        };
    }
}
