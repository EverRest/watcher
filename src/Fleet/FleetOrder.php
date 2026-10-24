<?php

declare(strict_types=1);

namespace Watcher\Fleet;

/** Priority (high first), then name; refined so a dependency that is in the fleet runs before its consumers. */
final class FleetOrder
{
    /**
     * @param list<ServiceEntry> $entries
     * @return list<ServiceEntry>
     */
    public static function of(array $entries): array
    {
        $sorted = $entries;
        usort($sorted, static fn (ServiceEntry $a, ServiceEntry $b): int => [$b->priority, $a->name()] <=> [$a->priority, $b->name()]);

        // providers[i] = indexes (into $sorted) of the fleet services entry i waits for
        $providers = [];
        foreach ($sorted as $i => $e) {
            $providers[$i] = [];
            foreach ($e->dependencies() as $dep) {
                foreach ($sorted as $j => $p) {
                    if ($j !== $i && ($p->name() === $dep || in_array($dep, $p->provides, true))) {
                        $providers[$i][$j] = $j;
                    }
                }
            }
        }

        $out = [];
        $left = array_keys($sorted);
        while ($left !== []) {
            $pick = null;
            foreach ($left as $i) {
                if (array_intersect($providers[$i], $left) === []) {
                    $pick = $i;
                    break;
                }
            }
            // Nothing is ready: every remaining service waits for another. Break the cycle at its first member in sorted order.
            $pick ??= self::firstInCycle($left, $providers);
            $out[] = $sorted[$pick];
            $left = array_values(array_diff($left, [$pick]));
        }

        return $out;
    }

    /**
     * @param list<int> $left
     * @param array<int, array<int, int>> $providers
     */
    private static function firstInCycle(array $left, array $providers): int
    {
        foreach ($left as $start) {
            $seen = [];
            $todo = array_intersect($providers[$start], $left);
            while ($todo !== []) {
                $n = array_pop($todo);
                if ($n === $start) {
                    return $start;
                }
                if (!isset($seen[$n])) {
                    $seen[$n] = true;
                    array_push($todo, ...array_intersect($providers[$n], $left));
                }
            }
        }

        return $left[0]; // unreachable: a stuck set always holds a cycle
    }
}
