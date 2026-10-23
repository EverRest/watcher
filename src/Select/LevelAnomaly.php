<?php

declare(strict_types=1);

namespace Watcher\Select;

use Watcher\Level;

/** FR-038: levels below read_min_level whose backend total jumped since the previous snapshot. */
final class LevelAnomaly
{
    /**
     * @param array<string, int>|null $previous null = no previous snapshot (first run: no second pass, FR-035)
     * @param array<string, int>      $current
     * @return list<Level>
     */
    public static function levels(?array $previous, array $current, float $growthFactor, Level $readMinLevel): array
    {
        if ($previous === null) {
            return [];
        }
        $out = [];
        foreach ($current as $name => $now) {
            $level = Level::parse((string) $name);
            if ($now <= 0 || $level->atLeast($readMinLevel)) {
                continue;
            }
            $was = $previous[$name] ?? 0;
            if ($was === 0 || $now >= $growthFactor * $was) {
                $out[] = $level;
            }
        }

        return $out;
    }
}
