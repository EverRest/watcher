<?php

declare(strict_types=1);

namespace Watcher\Context;

use Watcher\Level;
use Watcher\Reduce\Normalizer;

/** FR-039: decides, from the masked templates and the dependency totals, which dependencies need a line read. */
final class DependencyResolver
{
    /**
     * @param list<string|null>                $candidateTemplates redacted templates (null = withheld)
     * @param array<string, array<string, int>>      $currentTotals  every declared dep => level totals this run
     * @param array<string, array<string, int>|null> $previousTotals null/absent = no previous snapshot
     */
    public function plan(array $candidateTemplates, array $currentTotals, array $previousTotals, float $growthFactor): DependencyPlan
    {
        $names = array_map('strval', array_keys($currentTotals));
        $referencedBy = [];
        $undeclared = 0;

        foreach (array_unique(array_filter($candidateTemplates, 'is_string')) as $template) {
            $undeclared += preg_match_all('/<(?:URL|HOST)>/', $template);
        }

        foreach ($candidateTemplates as $i => $template) {
            if ($template === null) {
                continue;
            }
            foreach (Normalizer::declaredReferences($template) as $host) {
                $dep = null;
                foreach ($names as $name) {
                    if ($host === strtolower($name) || str_starts_with($host, strtolower($name) . '.')) {
                        $dep = $name;
                        break;
                    }
                }
                if ($dep !== null && !in_array($i, $referencedBy[$dep] ?? [], true)) {
                    $referencedBy[$dep][] = $i;
                }
            }
        }

        $linesFor = [];
        $first = [];
        foreach ($names as $name) {
            $prev = $previousTotals[$name] ?? null;
            $first[$name] = $prev === null;
            $grew = false;
            if ($prev !== null) {
                $now = self::errors($currentTotals[$name]);
                $was = self::errors($prev);
                $grew = $now > 0 && ($was === 0 || $now >= $growthFactor * $was);
            }
            if ($grew || isset($referencedBy[$name])) {
                $linesFor[] = $name;
            }
        }

        return new DependencyPlan($linesFor, $referencedBy, $undeclared, $first);
    }

    /**
     * Error-and-above total of a per-level totals map.
     *
     * @param array<string, int> $totals
     */
    public static function errors(array $totals): int
    {
        $sum = 0;
        foreach ($totals as $level => $n) {
            if (Level::parse((string) $level)->atLeast(Level::Error)) {
                $sum += $n;
            }
        }

        return $sum;
    }
}
