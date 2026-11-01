<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;

/** Validates the `grouping` section (clustering similarity and limits). */
final class GroupingSection
{
    /**
     * @param array<string, mixed> $g
     * @return array{similarity: float, message_cap: int, backend_patterns: bool, memory_budget_mb: int}
     */
    public static function grouping(array $g): array
    {
        SectionFields::only($g, ['similarity', 'message_cap', 'backend_patterns', 'memory_budget_mb'], 'grouping');
        $similarity = $g['similarity'] ?? 0.5;
        if ((!is_int($similarity) && !is_float($similarity)) || $similarity <= 0 || $similarity > 1) {
            throw new InvalidConfig('grouping.similarity must be greater than 0 and at most 1');
        }
        $cap = $g['message_cap'] ?? 50000;
        if (!is_int($cap) || $cap < 1000) {
            throw new InvalidConfig('grouping.message_cap must be an integer >= 1000');
        }
        if (($g['backend_patterns'] ?? false) !== false) {
            throw new InvalidConfig('grouping.backend_patterns is not available yet');
        }

        $memory = $g['memory_budget_mb'] ?? 32;
        if (!is_int($memory) || $memory < 1) {
            throw new InvalidConfig('grouping.memory_budget_mb must be an integer >= 1');
        }

        return ['similarity' => (float) $similarity, 'message_cap' => $cap, 'backend_patterns' => false, 'memory_budget_mb' => $memory];
    }
}
