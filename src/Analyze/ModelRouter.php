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
        private readonly ?TriageCheckpoint $checkpoint = null,
    ) {
    }

    /**
     * With a reservation, every billable step runs only if its worst case fits the daily cap; a spent budget at the start
     * ($triageOnly) is the documented triage-only mode and is not reserved against.
     *
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $dependencyContext indexes refer to positions in $groups; goes to analysis and escalation, never triage
     *
     * @throws AnalyzerFailed
     */
    public function route(array $groups, bool $triageOnly, array $dependencyContext = [], ?BudgetReservation $reservation = null): RouterResult
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
        // Triage paid for by an earlier attempt of this window whose analysis failed (R5).
        $verdicts = $this->s->triageEnabled ? ($this->checkpoint?->load($fresh) ?? []) : [];
        $pending = array_values(array_filter($fresh, static fn (RedactedGroup $g): bool => !isset($verdicts[$g->fingerprint])));
        if (!$this->s->triageEnabled) {
            // Triage off: no call, no checkpoint; every fresh group goes to analysis (top-M and the budget still apply).
            $verdicts = array_fill_keys(array_map(static fn (RedactedGroup $g): string => $g->fingerprint, $fresh), 'interesting');
            $pending = [];
        }
        $notTriaged = 0;
        if ($pending !== []) {
            if (!$triageOnly && $reservation !== null && !$reservation->allows($this->s->triageModel, $this->inputTokens('triage', $pending), AnthropicAnalyzer::triageOutputCap($this->s->triageMaxTokens, count($pending)))) {
                $notTriaged = count($pending);
            } else {
                $tri = $this->analyzer->triage(new TriageRequest($pending, $this->s->serviceContext, $this->s->triageModel, $this->s->triageMaxTokens));
                $usage['triage'] = $tri->usage;
                $verdicts += $tri->verdicts;
            }
        }
        $interesting = [];
        foreach ($fresh as $g) {
            $v = $verdicts[$g->fingerprint] ?? null;
            if ($v === null) {
                continue; // the model skipped it (or triage did not fit the budget): nothing is stored, it is triaged again next run
            }
            if ($v === 'interesting') {
                // Stored only after it was really analysed (below): a triage-only day or a top-M cut must not
                // turn "interesting but never looked at" into a reusable verdict.
                $interesting[] = $g;
            } else {
                $this->verdicts->storeTriage($g, $v);
            }
        }

        $findings = [];
        $dropped = 0;
        $escalations = 0;
        $skippedBudget = 0;
        $skippedSize = 0;
        $notAnalysed = $triageOnly ? count($interesting) : 0;
        if (!$triageOnly && $interesting !== []) {
            usort($interesting, static fn (RedactedGroup $a, RedactedGroup $b): int => self::priority($b) <=> self::priority($a)
                ?: $b->count <=> $a->count
                ?: strcmp($a->fingerprint, $b->fingerprint));
            $dropped = $notAnalysed = max(0, count($interesting) - $this->s->topM);
            $top = array_slice($interesting, 0, $this->s->topM);
            $topContext = self::remap($dependencyContext, $groups, $top);
            $analyzeCap = AnthropicAnalyzer::analysisOutputCap($this->s->analyzeMaxTokens, count($top), $this->s->advice);

            if ($reservation !== null && !$reservation->allows($this->s->analyzeModel, $this->inputTokens('analyze', $top, $topContext), $analyzeCap)) {
                $notAnalysed = count($interesting);
            } else {
                try {
                    $res = $this->analyzer->analyze(new AnalysisRequest($top, $this->s->serviceContext, $this->s->analyzeModel, $analyzeCap, $topContext));
                } catch (AnalyzerFailed $e) {
                    // Triage was paid for: keep its verdicts for the retry of this window.
                    if ($this->s->triageEnabled) {
                        $this->checkpoint?->save($verdicts, $fresh);
                    }

                    throw $e;
                }
                $usage['analyze'] = $res->usage;
                $findings = $res->findings;

                $byFp = [];
                foreach ($top as $g) {
                    $byFp[$g->fingerprint] = $g;
                }
                $out = [];
                foreach ($findings as $f) {
                    if ($escalations < $this->s->escalationCap && $this->needsEscalation($f)) {
                        $sub = array_values(array_filter(array_map(static fn (string $fp): ?RedactedGroup => $byFp[$fp] ?? null, $f->fingerprints)));
                        $fit = $this->fitEscalation($sub, self::remap($dependencyContext, $groups, $sub));
                        if ($fit === null) {
                            ++$skippedSize;
                            $out[] = $f;
                            continue;
                        }
                        [$sub, $subContext] = $fit;
                        $escalateCap = AnthropicAnalyzer::analysisOutputCap($this->s->escalateMaxTokens, count($sub), $this->s->advice);
                        if ($reservation !== null && !$reservation->allows($this->s->escalateModel, $this->inputTokens('analyze', $sub, $subContext), $escalateCap)) {
                            ++$skippedBudget;
                            $out[] = $f;
                            continue;
                        }
                        ++$escalations;
                        $again = $this->analyzer->analyze(new AnalysisRequest($sub, $this->s->serviceContext, $this->s->escalateModel, $escalateCap, $subContext));
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
                        $this->verdicts->put((string) $fp, 'interesting', $g->count, null); // all-digit keys come back as int
                    }
                }
            }
        }

        return new RouterResult($findings, $usage, $escalations, $reused, count($fresh) - $notTriaged, $dropped, $notAnalysed, $notTriaged, $skippedBudget, $skippedSize);
    }

    /**
     * Estimated input of one call: the system prompt with the service context, plus the payload.
     *
     * @param 'triage'|'analyze'      $kind
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $context
     */
    private function inputTokens(string $kind, array $groups, array $context = []): int
    {
        return Budget::estimateTokens(Budget::systemPrompt($kind, $this->s->serviceContext, $this->s->advice)) + Budget::estimateTokens(CompactPayload::encode($groups, $context));
    }

    /**
     * W3: an escalation re-sends one finding's groups and their dependency context; over analysis.max_input_tokens.escalate,
     * dependency context is trimmed first, then the groups' stack frames (the evidence), and only then the escalation is skipped.
     *
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $context
     *
     * @return array{0: list<RedactedGroup>, 1: list<DependencyContext>}|null null = does not fit
     */
    private function fitEscalation(array $groups, array $context): ?array
    {
        $max = $this->s->escalateMaxInputTokens;
        if ($this->inputTokens('analyze', $groups, $context) <= $max) {
            return [$groups, $context];
        }
        $context = Budget::fitDependencies($groups, $context, $max, Budget::estimateTokens(Budget::systemPrompt('analyze', $this->s->serviceContext, $this->s->advice)));
        if ($this->inputTokens('analyze', $groups, $context) <= $max) {
            return [$groups, $context];
        }
        $groups = array_map(static fn (RedactedGroup $g): RedactedGroup => new RedactedGroup($g->fingerprint, $g->template, $g->level, $g->errorType, [], $g->count, $g->previousCount, $g->status, $g->watchMatches, $g->firstSeen, $g->lastSeen), $groups);

        return $this->inputTokens('analyze', $groups, $context) <= $max ? [$groups, $context] : null;
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
            GroupStatusLabel::Unchanged, GroupStatusLabel::Expected => 0,
        } * 100 + $g->level->value;
    }
}
