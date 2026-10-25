<?php

declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Level;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\ProblemGroup;
use Watcher\Schedule\Window;
use Watcher\Select\Candidate;
use Watcher\Select\CandidateReason;

/**
 * Turns a metric move that no log finding explains into ordinary candidates (Principle IV: code decides). Anomaly
 * iff baseline exists, current > floor, relative increase >= threshold AND no pre-filter candidate is Error or above.
 * ponytail: direction is "up" only and status uses the previous window's snapshot, not the spec-004 baseline modes.
 */
final class MetricAnomalies
{
    /** @param list<MetricDefinition> $metrics */
    public function __construct(
        private readonly string $service,
        private readonly array $metrics,
        private readonly int $maxAnomalies,
        private readonly float $growthFactor,
    ) {
    }

    /**
     * @param list<Candidate>    $explaining candidates before the `reported` filter
     * @param array<string, int> $previous   previous snapshot: fingerprint => count
     */
    public function detect(MetricsContext $context, array $explaining, Window $window, array $previous): AnomalyResult
    {
        foreach ($explaining as $c) {
            if ($c->group->level->atLeast(Level::Error)) {
                return new AnomalyResult([], [], 0);
            }
        }
        $thresholds = [];
        foreach ($this->metrics as $m) {
            $thresholds[$m->name] = $m->threshold;
        }

        $hits = [];
        foreach ($context->readings as $r) {
            $t = $thresholds[$r->name] ?? null;
            $rel = $r->relativeChange();
            if ($t !== null && $rel !== null && $r->current > $t['floor'] && $rel >= $t['relative']) {
                $hits[] = [$r, $rel];
            }
        }
        usort($hits, static fn (array $a, array $b): int => [$b[1], $a[0]->name] <=> [$a[1], $b[0]->name]);

        $candidates = [];
        $by = [];
        foreach (array_slice($hits, 0, $this->maxAnomalies) as [$r, $rel]) {
            $fp = 'metric:' . substr(hash('sha256', $this->service . '|' . $r->name), 0, 12);
            $count = max(1, (int) round($rel * 100));
            $prev = $previous[$fp] ?? null;
            // template is the declared name (config, not log data): the Normalizer would turn p95 into p<N>
            $group = new ProblemGroup(
                $fp,
                'metric anomaly: ' . $r->name,
                Level::Warning,
                null,
                [],
                $count,
                $window->start,
                $window->end,
                $prev,
                GroupStatus::resolve($count, $prev, $this->growthFactor),
            );
            $candidates[] = new Candidate($group, [CandidateReason::MetricAnomaly], CandidateReason::MetricAnomaly->weight() * 10 + Level::Warning->value);
            $by[$fp] = $r->name;
        }

        return new AnomalyResult($candidates, $by, max(0, count($hits) - $this->maxAnomalies));
    }
}
