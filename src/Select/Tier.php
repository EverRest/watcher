<?php

declare(strict_types=1);

namespace Watcher\Select;

use Watcher\Level;
use Watcher\Reduce\ProblemGroup;

/**
 * Ranking rule of spec 009 (owner decision 2026-10-25): log groups at Error or above are never displaced by metric-anomaly or
 * Sentry groups. Log groups are selected exactly as before (top_n counts them alone); Sentry groups are selected on their own, with the top_n volume slots the log groups leave free,
 * and, with the anomalies, rank below the lowest log candidate at Error or above, so the budget cut and top_m only give them
 * the slots that remain.
 */
final class Tier
{
    /** A Sentry issue (`sentry:<id>`) or a metric anomaly (`metric:<hash>`): not a log group. */
    public static function isExtra(string $fingerprint): bool
    {
        return str_starts_with($fingerprint, 'sentry:') || str_starts_with($fingerprint, 'metric:');
    }

    /**
     * @param list<ProblemGroup> $groups        log and Sentry groups together
     * @param list<Level>        $anomalyLevels
     */
    public static function select(array $groups, Level $minLevel, int $topN, array $anomalyLevels): SelectionResult
    {
        $log = array_values(array_filter($groups, static fn (ProblemGroup $g): bool => !self::isExtra($g->fingerprint)));
        $extra = array_values(array_filter($groups, static fn (ProblemGroup $g): bool => self::isExtra($g->fingerprint)));
        $selector = new Selector();
        $primary = $selector->select($log, $minLevel, $topN, $anomalyLevels);
        if ($extra === []) {
            return $primary;
        }
        $secondary = $selector->select($extra, $minLevel, $topN - count($log), $anomalyLevels); // only the volume slots the log groups leave free

        return new SelectionResult(self::merge($primary->candidates, $secondary->candidates), $primary->otherGroups + $secondary->otherGroups, $primary->otherEvents + $secondary->otherEvents);
    }

    /**
     * @param list<Candidate> $log   log candidates, unchanged
     * @param list<Candidate> $extra Sentry and anomaly candidates: capped below the lowest log candidate at Error or above
     *
     * @return list<Candidate> ordered by priority desc, then fingerprint
     */
    public static function merge(array $log, array $extra): array
    {
        $floor = null;
        foreach ($log as $c) {
            if ($c->group->level->atLeast(Level::Error)) {
                $floor = $floor === null ? $c->priority : min($floor, $c->priority);
            }
        }
        if ($floor !== null) {
            $extra = array_map(static fn (Candidate $c): Candidate => $c->priority < $floor ? $c : new Candidate($c->group, $c->reasons, $floor - 1), $extra);
        }
        $all = [...$log, ...$extra];
        usort($all, static fn (Candidate $a, Candidate $b): int => [$b->priority, $a->group->fingerprint] <=> [$a->priority, $b->group->fingerprint]);

        return $all;
    }
}
