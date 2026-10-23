<?php

declare(strict_types=1);

namespace Watcher\Reduce;

use DateTimeImmutable;
use Watcher\Level;
use Watcher\Source\LogEvent;

/**
 * Folds an event stream into ProblemGroups by fingerprint. Memory is per group and per cluster
 * (one token list each), never per event. Sorted by count desc, then fingerprint.
 */
final class Reducer
{
    public function __construct(private readonly Normalizer $normalizer, private readonly int $minGroupEventsForText)
    {
    }

    /**
     * @param  iterable<LogEvent> $events
     * @return list<ProblemGroup>
     */
    public function reduce(iterable $events): array
    {
        $fingerprinter = new Fingerprinter($this->normalizer);
        $miner = new TemplateMiner($this->minGroupEventsForText);
        /** @var array<string, array{key: string, level: Level, type: ?string, frames: list<string>, count: int, first: DateTimeImmutable, last: DateTimeImmutable}> $groups */
        $groups = [];

        foreach ($events as $e) {
            $tokens = TemplateMiner::tokens($this->normalizer, $e);
            $key = $miner->add($e, $tokens);
            $fp = $fingerprinter->fingerprint($e, $tokens);
            if (!isset($groups[$fp])) {
                $groups[$fp] = ['key' => $key, 'level' => $e->level, 'type' => $e->errorType, 'frames' => $e->frames,
                    'count' => 1, 'first' => $e->time, 'last' => $e->time];
                continue;
            }
            $g = &$groups[$fp];
            $g['count']++;
            $g['level'] = $e->level->atLeast($g['level']) ? $e->level : $g['level'];
            $g['first'] = min($g['first'], $e->time);
            $g['last'] = max($g['last'], $e->time);
            unset($g);
        }

        $out = [];
        foreach ($groups as $fp => $g) {
            $out[] = new ProblemGroup($fp, $miner->template($g['key']), $g['level'], $g['type'], $g['frames'], $g['count'], $g['first'], $g['last']);
        }
        usort($out, static fn (ProblemGroup $a, ProblemGroup $b): int => [$b->count, $a->fingerprint] <=> [$a->count, $b->fingerprint]);

        return $out;
    }
}
