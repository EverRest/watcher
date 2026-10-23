<?php

declare(strict_types=1);

namespace Watcher\Reduce;

use DateTimeImmutable;
use Watcher\Level;
use Watcher\Source\LogEvent;

/**
 * Folds an event stream into ProblemGroups by fingerprint. Memory is per group and per cluster
 * (one token list each), never per event, and bounded: past `$groupCap` own groups a NEW fingerprint is
 * folded into one overflow group per level (never into the miner). An `$exempt` event (it matched a
 * watch rule) keeps its own group up to 2 x cap. Sorted by count desc, then fingerprint.
 */
final class Reducer
{
    public const DEFAULT_GROUP_CAP = 5000;
    public const OVERFLOW_TEMPLATE = '<other clusters>';

    private int $overflowed = 0;

    /**
     * @param (callable(LogEvent): bool)|null $exempt   true: the event must keep its own group (watch rule hit)
     * @param (callable(LogEvent): void)|null $onFolded called for each event folded into an overflow group
     */
    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly int $minGroupEventsForText,
        private readonly int $groupCap = self::DEFAULT_GROUP_CAP,
        private readonly mixed $exempt = null,
        private readonly mixed $onFolded = null,
    ) {
    }

    /** Events folded into overflow groups by the last reduce(). */
    public function overflowedEvents(): int
    {
        return $this->overflowed;
    }

    /**
     * @param  iterable<LogEvent> $events
     * @return list<ProblemGroup>
     */
    public function reduce(iterable $events): array
    {
        $fingerprinter = new Fingerprinter($this->normalizer);
        $miner = new TemplateMiner($this->minGroupEventsForText);
        /** @var array<string, array{key: ?string, level: Level, type: ?string, frames: list<string>, count: int, first: DateTimeImmutable, last: DateTimeImmutable}> $groups */
        $groups = [];
        $own = 0;
        $this->overflowed = 0;

        foreach ($events as $e) {
            $tokens = TemplateMiner::tokens($this->normalizer, $e);
            $fp = $fingerprinter->fingerprint($e, $tokens);
            $key = null;
            if (isset($groups[$fp])) {
                $key = $miner->add($e, $tokens);
            } elseif ($own < $this->groupCap || ($own < 2 * $this->groupCap && $this->exempt !== null && ($this->exempt)($e))) {
                $own++;
                $key = $miner->add($e, $tokens);
            } else {
                $fp = substr(hash('sha256', 'overflow|' . $e->level->value), 0, 12);
                $this->overflowed++;
                if ($this->onFolded !== null) {
                    ($this->onFolded)($e);
                }
            }
            if (!isset($groups[$fp])) {
                $groups[$fp] = ['key' => $key, 'level' => $e->level, 'type' => $key === null ? null : $e->errorType, 'frames' => $key === null ? [] : $e->frames,
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
            // PHP turns all-digit keys into ints; the fingerprint is a string everywhere else.
            $out[] = new ProblemGroup((string) $fp, $g['key'] === null ? self::OVERFLOW_TEMPLATE : $miner->template($g['key']), $g['level'], $g['type'], $g['frames'], $g['count'], $g['first'], $g['last']);
        }
        usort($out, static fn (ProblemGroup $a, ProblemGroup $b): int => [$b->count, $a->fingerprint] <=> [$a->count, $b->fingerprint]);

        return $out;
    }
}
