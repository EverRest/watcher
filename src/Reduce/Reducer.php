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

    private int $unscannable = 0;

    private MiningStats $mining;

    /** @var list<array{template: string, distinct: int}> */
    private array $variability = [];

    /**
     * @param (callable(LogEvent): bool)|null $exempt   true: the event must keep its own group (watch rule hit)
     * @param (callable(LogEvent): void)|null $onFolded called for each event folded into an overflow group
     * @param float $similarity           share of literal tokens that must match to join a cluster (0 < x <= 1)
     * @param int $maxDistinctMessages    distinct masked messages kept for clustering; more are dropped and their groups lose the text
     * @param int|null $comparisonBudget  token operations allowed in clustering (default 1000 x maxDistinctMessages)
     * @param int $memoryBudgetBytes      estimated bytes the miner may hold; past it new messages are dropped like past the message cap
     * @param bool $groupBySimilarity     false: old behaviour, one cluster per (level, type, token count, frame)
     */
    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly int $minGroupEventsForText,
        private readonly int $groupCap = self::DEFAULT_GROUP_CAP,
        private readonly mixed $exempt = null,
        private readonly mixed $onFolded = null,
        private readonly float $similarity = TemplateMiner::DEFAULT_SIMILARITY,
        private readonly int $maxDistinctMessages = TemplateMiner::DEFAULT_MAX_DISTINCT,
        private readonly ?int $comparisonBudget = null,
        private readonly bool $groupBySimilarity = true,
        private readonly int $memoryBudgetBytes = TemplateMiner::DEFAULT_MEMORY_BUDGET,
    ) {
        $this->mining = new MiningStats();
    }

    /** What the template miner gave up in the last reduce() (spec 004 FR-010). */
    public function mining(): MiningStats
    {
        return $this->mining;
    }

    /** Events the Normalizer could not scan in the last reduce(): masked as `<UNSCANNABLE>`, never raw (A4-07). */
    public function unscannableEvents(): int
    {
        return $this->unscannable;
    }

    /**
     * Templates with a varying part and the number of distinct masked messages each folds, from the last reduce() (spec 006).
     *
     * @return list<array{template: string, distinct: int}>
     */
    public function variability(): array
    {
        return $this->variability;
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
        $miner = new TemplateMiner($this->minGroupEventsForText, $this->similarity, $this->maxDistinctMessages, $this->comparisonBudget, $this->groupBySimilarity, $this->memoryBudgetBytes);
        /** @var array<string, array{own: bool, level: Level, type: ?string, frames: list<string>, count: int, first: DateTimeImmutable, last: DateTimeImmutable}> $groups */
        $groups = [];
        $ownCount = 0;
        $this->overflowed = 0;
        $unscannableBefore = $this->normalizer->unscannable;

        foreach ($events as $e) {
            // One poisoned event must not abort the run (A4-07): it is grouped, counted and masked as <UNSCANNABLE>.
            $tokens = TemplateMiner::tokens($this->normalizer, $e);
            $fp = $fingerprinter->fingerprint($e, $tokens);
            $own = true;
            if (isset($groups[$fp])) {
                $miner->add($e, $tokens, $fp);
            } elseif ($ownCount < $this->groupCap || ($ownCount < 2 * $this->groupCap && $this->exempt !== null && ($this->exempt)($e))) {
                $ownCount++;
                $miner->add($e, $tokens, $fp);
            } else {
                $own = false;
                $fp = substr(hash('sha256', 'overflow|' . $e->level->value), 0, 12);
                $this->overflowed++;
                if ($this->onFolded !== null) {
                    ($this->onFolded)($e);
                }
            }
            if (!isset($groups[$fp])) {
                $groups[$fp] = ['own' => $own, 'level' => $e->level, 'type' => $own ? $e->errorType : null, 'frames' => $own ? $e->frames : [],
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

        $this->unscannable = $this->normalizer->unscannable - $unscannableBefore;
        $out = [];
        foreach ($groups as $fp => $g) {
            // PHP turns all-digit keys into ints; the fingerprint is a string everywhere else.
            $template = $g['own'] ? $miner->template((string) $fp) : self::OVERFLOW_TEMPLATE;
            if ($template === '<UNSCANNABLE>') {
                $template = null; // a group nothing could scan has no template text, not even the placeholder
            }
            $out[] = new ProblemGroup((string) $fp, $template, $g['level'], $g['type'], $g['frames'], $g['count'], $g['first'], $g['last']);
        }
        $this->mining = $miner->stats();
        $this->variability = $miner->variability();
        usort($out, static fn (ProblemGroup $a, ProblemGroup $b): int => [$b->count, $a->fingerprint] <=> [$a->count, $b->fingerprint]);

        return $out;
    }
}
