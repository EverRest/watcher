<?php

declare(strict_types=1);

namespace Watcher\Context;

use Watcher\Level;

/** FR-039: decides, from the masked templates and the dependency totals, which dependencies need a line read. */
final class DependencyResolver
{
    public const MAX_UNDECLARED = 10;

    /**
     * @param list<string|null>                $candidateTemplates redacted templates (null = withheld)
     * @param array<string, array<string, int>>      $currentTotals  every declared dep => level totals this run
     * @param array<string, array<string, int>|null> $previousTotals null/absent = no previous snapshot
     */
    public function plan(array $candidateTemplates, array $currentTotals, array $previousTotals, float $growthFactor): DependencyPlan
    {
        $names = array_map('strval', array_keys($currentTotals));
        $referencedBy = [];
        $undeclared = [];

        foreach ($candidateTemplates as $i => $template) {
            if ($template === null || preg_match_all('/<(?:URL|HOST):([a-z0-9.-]+)>/', $template, $m) < 1) {
                continue;
            }
            foreach (array_unique($m[1]) as $host) {
                $dep = null;
                foreach ($names as $name) {
                    if ($host === strtolower($name) || str_starts_with($host, strtolower($name) . '.')) {
                        $dep = $name;
                        break;
                    }
                }
                if ($dep === null) {
                    $undeclared[$host] = true;
                } elseif (!in_array($i, $referencedBy[$dep] ?? [], true)) {
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

        $hosts = array_map('strval', array_keys($undeclared));
        sort($hosts);

        return new DependencyPlan($linesFor, $referencedBy, array_slice($hosts, 0, self::MAX_UNDECLARED), $first);
    }

    /** @param array<string, int> $totals */
    private static function errors(array $totals): int
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
