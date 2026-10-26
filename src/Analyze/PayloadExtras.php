<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Metrics\MetricsContext;
use Watcher\Privacy\RedactedGroup;

/**
 * The measured-metrics section `m` of the model input (spec 009, FR-006). Built from the declared metric names (config) and the
 * numbers the reader measured, after redaction and never from log text, so the Redactor's digit masking cannot touch it and no
 * label value, issue title or query can reach it. At most {@see self::MAX_METRICS} metrics; an anomaly's own metric comes first.
 */
final class PayloadExtras
{
    public const MAX_METRICS = 3;

    /**
     * @param list<string>               $names   declared metric names (config)
     * @param list<array{?float, ?float}> $pairs   [current, baseline] at 3 significant digits, null = none
     * @param list<string>               $units   display unit per metric ('' = none)
     * @param list<?float>               $changes relative change (0.25 = +25%), null without a positive baseline
     * @param array<string, int>         $anomalyIndex anomaly group fingerprint => index into $names
     */
    private function __construct(
        public readonly array $names,
        public readonly array $pairs,
        public readonly array $units,
        public readonly array $changes,
        public readonly array $anomalyIndex,
    ) {
    }

    /**
     * @param array<string, string> $anomalyMetric fingerprint => declared metric name
     */
    public static function fromContext(MetricsContext $context, array $anomalyMetric = []): ?self
    {
        $pairs = $context->pairs();
        $rows = [];
        foreach ($context->readings as $i => $r) {
            if ($r->current !== null && in_array($r->state, ['ok', 'no_baseline'], true)) {
                $rows[] = [$r, $pairs[$i], in_array($r->name, $anomalyMetric, true)];
            }
        }
        if ($rows === []) {
            return null;
        }
        usort($rows, static fn (array $a, array $b): int => [$b[2], abs((float) $b[0]->relativeChange())] <=> [$a[2], abs((float) $a[0]->relativeChange())]);
        $rows = array_slice($rows, 0, self::MAX_METRICS);
        $names = array_map(static fn (array $r): string => $r[0]->name, $rows);
        $index = [];
        foreach ($anomalyMetric as $fp => $name) {
            $at = array_search($name, $names, true);
            if ($at !== false) {
                $index[(string) $fp] = $at;
            }
        }

        return new self(
            $names,
            array_map(static fn (array $r): array => $r[1], $rows),
            array_map(static fn (array $r): string => $r[0]->unit, $rows),
            array_map(static fn (array $r): ?float => ($c = $r[0]->relativeChange()) === null ? null : round($c, 2), $rows),
            $index,
        );
    }

    /** The first $n metrics (anomaly metrics come first); null when none are left. */
    public function limit(int $n): ?self
    {
        if ($n <= 0) {
            return null;
        }
        $n = min($n, count($this->names));

        return new self(array_slice($this->names, 0, $n), array_slice($this->pairs, 0, $n), array_slice($this->units, 0, $n), array_slice($this->changes, 0, $n), array_filter($this->anomalyIndex, static fn (int $i): bool => $i < $n));
    }

    /**
     * Triage sees the numbers only when a group in its batch is a metric anomaly; analysis always does.
     *
     * @param list<RedactedGroup> $groups
     */
    public function forTriage(array $groups): ?self
    {
        foreach ($groups as $g) {
            if (isset($this->anomalyIndex[$g->fingerprint])) {
                return $this;
            }
        }

        return null;
    }

    /** @return array{n: list<string>, v: list<array{?float, ?float}>, u: list<string>, r: list<?float>} */
    public function section(): array
    {
        return ['n' => $this->names, 'v' => $this->pairs, 'u' => $this->units, 'r' => $this->changes];
    }

    public function indexFor(string $fingerprint): ?int
    {
        return $this->anomalyIndex[$fingerprint] ?? null;
    }
}
