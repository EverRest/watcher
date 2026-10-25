<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use Watcher\Privacy\Redactor;

/**
 * Variable free text (FR-002): the miner folds the words that differ between events into `<*>`, and the number of distinct
 * masked messages folded under one template is how many different texts hide behind one sentence, which is what a name in
 * free text looks like. Counts only (research R5).
 */
final class VariablePositions
{
    public function __construct(private readonly Redactor $redactor)
    {
    }

    /**
     * @param list<array{template: string, distinct: int}> $variability from {@see \Watcher\Reduce\Reducer::variability()}
     * @return list<array{tpl: string, positions: int, distinct_messages: int}> biggest first
     */
    public function find(array $variability, int $minDistinct): array
    {
        /** @var array<string, int> $count */
        $count = [];
        foreach ($variability as $v) {
            $count[$v['template']] = ($count[$v['template']] ?? 0) + $v['distinct'];
        }
        $rows = [];
        foreach ($count as $template => $n) {
            // The second scan every template passes before it leaves the service; an unscannable one is dropped.
            $safe = $n >= $minDistinct ? $this->redactor->redactText((string) $template, false) : null;
            if ($safe !== null) {
                $rows[] = ['tpl' => $safe, 'positions' => substr_count($safe, '<*>'), 'distinct_messages' => $n];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['distinct_messages'], $a['tpl']] <=> [$a['distinct_messages'], $b['tpl']]);

        return $rows;
    }
}
