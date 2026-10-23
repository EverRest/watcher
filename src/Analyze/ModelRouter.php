<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Context\DependencyContext;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

/** Cost routing: reuse verdicts, one cheap triage, one analysis of the top-M, capped escalation (FR-016..FR-020). */
final class ModelRouter
{
    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly VerdictCache $verdicts,
        private readonly RouterSettings $s,
    ) {
    }

    /**
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $dependencyContext indexes refer to positions in $groups; goes to analysis and escalation, never triage
     *
     * @throws AnalyzerFailed
     */
    public function route(array $groups, bool $triageOnly, array $dependencyContext = []): RouterResult
    {
        $fresh = [];
        $reused = 0;
        foreach ($groups as $g) {
            if ($this->verdicts->isReusable($g, $this->s->growthFactor)) {
                ++$reused;
            } else {
                $fresh[] = $g;
            }
        }
        if ($fresh === []) {
            return new RouterResult([], [], 0, $reused, 0, 0);
        }

        $usage = [];
        $tri = $this->analyzer->triage(new TriageRequest($fresh, $this->s->serviceContext, $this->s->triageModel, $this->s->triageMaxTokens));
        $usage['triage'] = $tri->usage;
        $interesting = [];
        foreach ($fresh as $g) {
            $v = $tri->verdicts[$g->fingerprint] ?? null;
            if ($v === null) {
                continue; // the model skipped it: nothing is stored, it is triaged again next run
            }
            if ($v === 'interesting') {
                // Stored only after it was really analysed (below): a triage-only day or a top-M cut must not
                // turn "interesting but never looked at" into a reusable verdict.
                $interesting[] = $g;
            } else {
                $this->verdicts->put($g->fingerprint, $v, $g->count, null);
            }
        }

        $findings = [];
        $dropped = 0;
        $escalations = 0;
        if (!$triageOnly && $interesting !== []) {
            usort($interesting, static fn (RedactedGroup $a, RedactedGroup $b): int => self::priority($b) <=> self::priority($a)
                ?: $b->count <=> $a->count
                ?: strcmp($a->fingerprint, $b->fingerprint));
            $dropped = max(0, count($interesting) - $this->s->topM);
            $top = array_slice($interesting, 0, $this->s->topM);

            $res = $this->analyzer->analyze(new AnalysisRequest($top, $this->s->serviceContext, $this->s->analyzeModel, $this->s->analyzeMaxTokens, self::remap($dependencyContext, $groups, $top)));
            $usage['analyze'] = $res->usage;
            $findings = $res->findings;

            $byFp = [];
            foreach ($top as $g) {
                $byFp[$g->fingerprint] = $g;
            }
            $out = [];
            foreach ($findings as $f) {
                if ($escalations < $this->s->escalationCap && $this->needsEscalation($f)) {
                    ++$escalations;
                    $sub = array_values(array_filter(array_map(static fn (string $fp): ?RedactedGroup => $byFp[$fp] ?? null, $f->fingerprints)));
                    $again = $this->analyzer->analyze(new AnalysisRequest($sub, $this->s->serviceContext, $this->s->escalateModel, $this->s->escalateMaxTokens, self::remap($dependencyContext, $groups, $sub)));
                    $usage['escalate'] = isset($usage['escalate']) ? $usage['escalate']->plus($again->usage) : $again->usage;
                    if ($again->findings !== []) {
                        foreach ($again->findings as $e) {
                            $out[] = $e->escalated();
                        }
                        continue;
                    }
                }
                $out[] = $f;
            }
            $findings = $out;

            // Every group that was actually analysed is remembered — with its finding, or without one when the
            // analysis found nothing worth reporting — so it is not paid for again until it grows.
            $withFinding = [];
            foreach ($findings as $f) {
                foreach ($f->fingerprints as $fp) {
                    if (isset($byFp[$fp])) {
                        $withFinding[$fp] = true;
                        $this->verdicts->put($fp, 'interesting', $byFp[$fp]->count, $f);
                    }
                }
            }
            foreach ($byFp as $fp => $g) {
                if (!isset($withFinding[$fp])) {
                    $this->verdicts->put($fp, 'interesting', $g->count, null);
                }
            }
        }

        return new RouterResult($findings, $usage, $escalations, $reused, count($fresh), $dropped);
    }

    /**
     * Re-points each dependency's references from positions in $from to positions in $to (same groups, by fingerprint).
     *
     * @param list<DependencyContext> $contexts
     * @param list<RedactedGroup>     $from
     * @param list<RedactedGroup>     $to
     *
     * @return list<DependencyContext>
     */
    private static function remap(array $contexts, array $from, array $to): array
    {
        $pos = [];
        foreach ($to as $i => $g) {
            $pos[$g->fingerprint] = $i;
        }

        return array_map(static function (DependencyContext $c) use ($from, $pos): DependencyContext {
            $idx = [];
            foreach ($c->referencedByTemplateIndexes as $i) {
                $p = isset($from[$i]) ? ($pos[$from[$i]->fingerprint] ?? null) : null;
                if ($p !== null) {
                    $idx[] = $p;
                }
            }

            return $c->withReferences($idx);
        }, $contexts);
    }

    private function needsEscalation(Finding $f): bool
    {
        return in_array($f->severity, [Severity::Error, Severity::Critical], true) && $f->confidence < $this->s->confidenceThreshold;
    }

    /** new > grown > unchanged, then level */
    private static function priority(RedactedGroup $g): int
    {
        return match ($g->status) {
            GroupStatusLabel::New => 2,
            GroupStatusLabel::Grown => 1,
            GroupStatusLabel::Unchanged => 0,
        } * 100 + $g->level->value;
    }
}
