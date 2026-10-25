<?php

declare(strict_types=1);

namespace Watcher\Select;

use Watcher\Level;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\ProblemGroup;

/** Picks the groups worth analysing. Baseline (first run) and muting are the pipeline's job, not handled here. */
final class Selector
{
    /**
     * @param list<ProblemGroup>  $groups
     * @param list<Level>         $anomalyLevels
     * @param array<string, true> $knownGrown
     */
    public function select(array $groups, Level $minLevel, int $topN, array $anomalyLevels = [], array $knownGrown = []): SelectionResult
    {
        $ranked = $groups;
        usort($ranked, static fn (ProblemGroup $a, ProblemGroup $b) => [$b->count, $a->fingerprint] <=> [$a->count, $b->fingerprint]);
        $top = array_flip(array_map(static fn (ProblemGroup $g) => $g->fingerprint, array_slice($ranked, 0, max(0, $topN))));

        $candidates = [];
        $otherGroups = $otherEvents = 0;
        foreach ($groups as $g) {
            $reasons = [];
            if ($g->watchMatches !== []) {
                $reasons[] = CandidateReason::WatchRule;
            }
            if ($g->status === GroupStatus::New) {
                $reasons[] = CandidateReason::New;
            }
            if ($g->status === GroupStatus::Grown) {
                $reasons[] = CandidateReason::Grown;
            }
            if (isset($knownGrown[$g->fingerprint])) {
                $reasons[] = CandidateReason::KnownGrown;
            }
            if ($g->level->atLeast($minLevel)) {
                $reasons[] = CandidateReason::Level;
            }
            if (in_array($g->level, $anomalyLevels, true)) {
                $reasons[] = CandidateReason::LevelAnomaly;
            }
            if (isset($top[$g->fingerprint])) {
                $reasons[] = CandidateReason::TopVolume;
            }
            if ($reasons === []) {
                $otherGroups++;
                $otherEvents += $g->count;
                continue;
            }
            $weight = max(array_map(static fn (CandidateReason $r) => $r->weight(), $reasons));
            $candidates[] = new Candidate($g, $reasons, $weight * 10 + $g->level->value);
        }
        usort($candidates, static fn (Candidate $a, Candidate $b) => [$b->priority, $a->group->fingerprint] <=> [$a->priority, $b->group->fingerprint]);

        return new SelectionResult($candidates, $otherGroups, $otherEvents);
    }
}
