<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Watcher\State\StateStore;

/**
 * Reader of the last-report record `lastrep:{key}` (data-model.md), written by a real run. Validates the shape; anything else is "no report".
 *
 * INTEGRATOR: the key builder belongs in `Keys` (T020: `Keys::LASTREPORT = 'lastrep'` + `Keys::lastReport($service)`); {@see self::key()} is
 * the same string until that lands, and the call site should then switch to the builder.
 */
final class LastReport
{
    public function __construct(private readonly StateStore $store, private readonly string $service)
    {
    }

    public static function key(string $service): string
    {
        return 'lastrep:' . rawurlencode($service);
    }

    /** @return array{windowStart: mixed, windowEnd: mixed, outcome: mixed, at: mixed, counts: array<mixed>, findings: list<array<mixed>>}|null */
    public function get(): ?array
    {
        $r = $this->store->get(self::key($this->service));
        if ($r === null || ($r['v'] ?? null) !== 1 || !is_array($r['counts'] ?? null) || !is_array($r['findings'] ?? null)) {
            return null;
        }
        $findings = [];
        foreach ($r['findings'] as $f) {
            if (is_array($f)) {
                $findings[] = $f;
            }
        }

        return ['windowStart' => $r['windowStart'] ?? null, 'windowEnd' => $r['windowEnd'] ?? null, 'outcome' => $r['outcome'] ?? null, 'at' => $r['at'] ?? null, 'counts' => $r['counts'], 'findings' => $findings];
    }
}
