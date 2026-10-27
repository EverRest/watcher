<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Context\DependencyContext;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\Select\Tier;

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
     * @param array<string, string>   $endpoints fingerprint => `ep` (spec 010); analysis and escalation only, never triage
     *
     * @throws AnalyzerFailed
     */
    public function route(array $groups, bool $triageOnly, array $dependencyContext = [], ?BudgetReservation $reservation = null, array $endpoints = []): RouterResult
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
        $sent = [];
        if ($pending !== []) {
            if (!$triageOnly && $reservation !== null && !self::fits($reservation, $this->s->triageModel, $this->inputTokens('triage', $pending, [], $this->s->metrics?->forTriage($pending)), AnthropicAnalyzer::triageOutputCap($this->s->triageMaxTokens, count($pending)))) {
                $notTriaged = count($pending);
            } else {
                $tri = $this->analyzer->triage(new TriageRequest($pending, $this->s->serviceContext, $this->s->triageModel, $this->s->triageMaxTokens, $this->s->metrics?->forTriage($pending)));
                $usage['triage'] = $tri->usage;
                $verdicts += $tri->verdicts;
                $sent = array_flip(array_map(static fn (RedactedGroup $g): string => $g->fingerprint, $pending));
            }
        }
        // Fail closed: the cheap filter saves money on low-level groups, it does not get to veto an error or a watch rule.
        // A noise/known verdict becomes "interesting" for those; a group that was sent and got no verdict at all does for every group.
        $coerced = 0;
        foreach ($fresh as $g) {
            $v = $verdicts[$g->fingerprint] ?? null;
            if ($v !== 'interesting' && ($v === null ? isset($sent[$g->fingerprint]) : VerdictCache::mustNotBeSilenced($g))) {
                $verdicts[$g->fingerprint] = 'interesting';
                ++$coerced;
            }
        }
        $interesting = [];
        $triageDropped = 0;
        foreach ($fresh as $g) {
            $v = $verdicts[$g->fingerprint] ?? null;
            if ($v === null) {
                continue; // triage did not fit the budget: nothing is stored, it is triaged again next run
            }
            if ($v === 'interesting') {
                // Stored only after it was really analysed (below): a triage-only day or a top-M cut must not
                // turn "interesting but never looked at" into a reusable verdict.
                $interesting[] = $g;
            } else {
                $this->verdicts->storeTriage($g, $v);
                ++$triageDropped;
            }
        }

        $findings = [];
        $dropped = 0;
        $escalations = 0;
        $skippedBudget = 0;
        $skippedSize = 0;
        $notAnalysed = $triageOnly ? count($interesting) : 0;
        $unreported = 0;
        $newOverflow = 0;
        if (!$triageOnly && $interesting !== []) {
            usort($interesting, $this->byPriority(...));
            [$top, $newOverflow] = $this->pickTop($interesting);
            $dropped = $notAnalysed = count($interesting) - count($top);
            $topContext = self::remap($dependencyContext, $groups, $top);
            $topEndpoints = self::only($endpoints, $top);
            $analyzeCap = AnthropicAnalyzer::analysisOutputCap($this->s->analyzeMaxTokens, count($top), $this->s->advice);

            // `ep` is context, not evidence: a budget that fits without it still gets the analysis
            if ($reservation !== null && $topEndpoints !== [] && !self::fits($reservation, $this->s->analyzeModel, $this->inputTokens('analyze', $top, $topContext, $this->s->metrics, $topEndpoints), $analyzeCap)) {
                $topEndpoints = [];
            }
            if ($reservation !== null && !self::fits($reservation, $this->s->analyzeModel, $this->inputTokens('analyze', $top, $topContext, $this->s->metrics, $topEndpoints), $analyzeCap)) {
                $notAnalysed = count($interesting);
                $newOverflow = 0;
            } else {
                try {
                    $res = $this->analyzer->analyze(new AnalysisRequest($top, $this->s->serviceContext, $this->s->analyzeModel, $analyzeCap, $topContext, $this->s->metrics, $topEndpoints));
                } catch (AnalyzerFailed $e) {
                    // Triage was paid for: keep its verdicts for the retry of this window.
                    if ($this->s->triageEnabled) {
                        $this->checkpoint?->save($verdicts, $fresh);
                    }

                    throw $e;
                }
                $usage['analyze'] = $res->usage;

                $byFp = [];
                foreach ($top as $g) {
                    $byFp[$g->fingerprint] = $g;
                }
                $detached = [];
                $out = [];
                foreach ($this->vet($res->findings, $byFp, $detached) as [$f, $weak]) {
                    if ($escalations < $this->s->escalationCap && $this->needsEscalation($f, $byFp)) {
                        $sub = array_values(array_filter(array_map(static fn (string $fp): ?RedactedGroup => $byFp[$fp] ?? null, $f->fingerprints)));
                        $fit = $this->fitEscalation($sub, self::remap($dependencyContext, $groups, $sub));
                        if ($fit === null) {
                            ++$skippedSize;
                            $out[] = [$f, $weak];
                            continue;
                        }
                        [$sub, $subContext] = $fit;
                        $subEndpoints = self::only($endpoints, $sub);
                        if ($this->inputTokens('analyze', $sub, $subContext, $this->s->metrics, $subEndpoints) > $this->s->escalateMaxInputTokens) {
                            $subEndpoints = []; // context, not evidence: it goes before anything else is cut
                        }
                        $escalateCap = AnthropicAnalyzer::analysisOutputCap($this->s->escalateMaxTokens, count($sub), $this->s->advice);
                        if ($reservation !== null && $subEndpoints !== [] && !self::fits($reservation, $this->s->escalateModel, $this->inputTokens('analyze', $sub, $subContext, $this->s->metrics, $subEndpoints), $escalateCap)) {
                            $subEndpoints = [];
                        }
                        if ($reservation !== null && !self::fits($reservation, $this->s->escalateModel, $this->inputTokens('analyze', $sub, $subContext, $this->s->metrics, $subEndpoints), $escalateCap)) {
                            ++$skippedBudget;
                            $out[] = [$f, $weak];
                            continue;
                        }
                        ++$escalations;
                        $again = $this->analyzer->analyze(new AnalysisRequest($sub, $this->s->serviceContext, $this->s->escalateModel, $escalateCap, $subContext, $this->s->metrics, $subEndpoints, 'escalate'));
                        $usage['escalate'] = isset($usage['escalate']) ? $usage['escalate']->plus($again->usage) : $again->usage;
                        if ($again->findings !== []) {
                            foreach ($this->vet($again->findings, $byFp, $detached) as [$e, $w]) {
                                $out[] = [$e->escalated(), $w];
                            }
                            continue;
                        }
                    }
                    $out[] = [$f, $weak];
                }
                $findings = array_map(static fn (array $p): Finding => $p[0], $out);

                // What is remembered (and so not paid for again until it grows) is decided here, not by the model:
                // an error-or-higher / watch-rule group is remembered only with a substantive finding; "nothing to report"
                // or only noise/info for it is shown or counted, then analysed again next window.
                $strong = $weakOnly = [];
                foreach ($out as [$f, $weak]) {
                    foreach ($f->fingerprints as $fp) {
                        if (!isset($byFp[$fp])) {
                            continue;
                        }
                        if ($weak) {
                            $weakOnly[$fp] ??= $f;
                        } else {
                            $strong[$fp] ??= $f;
                        }
                    }
                }
                foreach ($byFp as $fp => $g) {
                    $fp = (string) $fp; // all-digit keys come back as int
                    if (isset($strong[$fp])) {
                        $this->verdicts->put($fp, 'interesting', $g->count, $strong[$fp]);
                    } elseif (isset($weakOnly[$fp])) {
                        if (!VerdictCache::mustNotBeSilenced($g)) {
                            $this->verdicts->put($fp, 'interesting', $g->count, $weakOnly[$fp]);
                        }
                    } elseif (VerdictCache::mustNotBeSilenced($g)) {
                        ++$unreported;
                    } elseif (!isset($detached[$fp])) {
                        $this->verdicts->put($fp, 'interesting', $g->count, null);
                    }
                }
            }
        }

        return new RouterResult($findings, $usage, $escalations, $reused, count($fresh) - $notTriaged, $dropped, $notAnalysed, $notTriaged, $skippedBudget, $skippedSize, $unreported, $triageDropped, $coerced, $newOverflow);
    }

    /**
     * Estimated input of one call: the system prompt with the service context, plus the payload.
     *
     * @param 'triage'|'analyze'      $kind
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $context
     * @param array<string, string>   $endpoints
     */
    private function inputTokens(string $kind, array $groups, array $context = [], ?PayloadExtras $extras = null, array $endpoints = []): int
    {
        return Budget::fixedTokens($kind, $this->s->serviceContext, $this->s->advice, $extras !== null, $endpoints !== []) + Budget::estimateTokens(CompactPayload::encode($groups, $context, $extras, $endpoints));
    }

    /**
     * @param array<string, string> $endpoints
     * @param list<RedactedGroup>   $groups
     *
     * @return array<string, string> the entries of the groups that are sent
     */
    private static function only(array $endpoints, array $groups): array
    {
        return array_intersect_key($endpoints, array_flip(array_map(static fn (RedactedGroup $g): string => $g->fingerprint, $groups)));
    }

    /** A step can take two calls (a retry on invalid output, with up to twice the output cap): reserve for both. */
    private static function fits(BudgetReservation $reservation, string $model, int $inputTokens, int $maxOutputTokens): bool
    {
        return $reservation->allows($model, 2 * $inputTokens, $maxOutputTokens + AnthropicAnalyzer::retryOutputCap($maxOutputTokens));
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
        if ($this->inputTokens('analyze', $groups, $context, $this->s->metrics) <= $max) {
            return [$groups, $context];
        }
        $context = Budget::fitDependencies($groups, $context, $max, Budget::fixedTokens('analyze', $this->s->serviceContext, $this->s->advice));
        if ($this->inputTokens('analyze', $groups, $context, $this->s->metrics) <= $max) {
            return [$groups, $context];
        }
        $groups = array_map(static fn (RedactedGroup $g): RedactedGroup => new RedactedGroup($g->fingerprint, $g->template, $g->level, $g->errorType, [], $g->count, $g->previousCount, $g->status, $g->watchMatches, $g->firstSeen, $g->lastSeen, origin: $g->origin, pods: $g->pods, workloadKind: $g->workloadKind), $groups);

        return $this->inputTokens('analyze', $groups, $context, $this->s->metrics) <= $max ? [$groups, $context] : null;
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

    /**
     * Code signals, not model claims: only a group the logs show at error or above can buy the expensive model, and only
     * for a finding the model itself rates severe and unsure of.
     *
     * @param array<string, RedactedGroup> $byFp
     */
    private function needsEscalation(Finding $f, array $byFp): bool
    {
        if (!in_array($f->severity, [Severity::Error, Severity::Critical], true) || !($f->confidence < $this->s->confidenceThreshold)) {
            return false;
        }
        foreach ($f->fingerprints as $fp) {
            if (isset($byFp[$fp]) && $byFp[$fp]->level->value >= Level::Error->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * F9 + F4: a noise/info finding may only stay on the groups the payload shows to be of one kind (same error type) as
     * the first one it cites, and severity/kind are bounded by what the logs show.
     *
     * @param list<Finding>                $findings
     * @param array<string, RedactedGroup> $byFp
     * @param array<string, true>          $detached fingerprints taken off a weak finding (out)
     *
     * @return list<array{0: Finding, 1: bool}> the bounded finding and whether the model itself called it noise/info
     */
    private function vet(array $findings, array $byFp, array &$detached): array
    {
        $out = [];
        foreach ($findings as $f) {
            $weak = VerdictCache::isWeak($f);
            $fps = $f->fingerprints;
            if ($weak && count($fps) > 1) {
                $first = $byFp[$fps[0]] ?? null;
                $keep = [$fps[0]];
                foreach (array_slice($fps, 1) as $fp) {
                    if ($first?->errorType !== null && ($byFp[$fp]->errorType ?? null) === $first->errorType) {
                        $keep[] = $fp;
                    } else {
                        $detached[$fp] = true;
                    }
                }
                $fps = array_values(array_unique($keep));
            }
            $out[] = [$this->bound($f, $fps, $byFp), $weak];
        }

        return $out;
    }

    /**
     * Severity: at most one step above what the logs show for the covered groups, and at least warn for an error-or-higher /
     * watch-rule group. Kind: such a group is never "noise".
     *
     * @param list<string>                 $fps
     * @param array<string, RedactedGroup> $byFp
     */
    private function bound(Finding $f, array $fps, array $byFp): Finding
    {
        $level = null;
        $protected = false;
        $floor = 0;
        foreach ($fps as $fp) {
            if (isset($byFp[$fp])) {
                $level = max($level ?? 0, $byFp[$fp]->level->value);
                $protected = $protected || VerdictCache::mustNotBeSilenced($byFp[$fp]);
                $floor = max($floor, VerdictCache::severityFloor($byFp[$fp])?->rank() ?? 0);
            }
        }
        if ($level === null) {
            return $f->with($fps, $f->kind, $f->severity);
        }
        $order = [Severity::Info, Severity::Warn, Severity::Error, Severity::Critical];
        $observed = match (true) {
            $level >= Level::Critical->value => 3,
            $level >= Level::Error->value => 2,
            $level >= Level::Warning->value => 1,
            default => 0,
        };
        $rank = min((int) array_search($f->severity, $order, true), min(3, $observed + 1));
        // The logs already show the level: a model may raise a finding above it, never put it below (see VerdictCache::severityFloor).
        $rank = max($rank, $floor);
        $kind = $protected && $f->kind === FindingKind::Noise ? FindingKind::Anomaly : $f->kind;

        return $f->with($fps, $kind, $order[$rank]);
    }

    /** watch rule, then level, then growth; novelty last (a minted fingerprint is always new); then volume, then a salted hash. */
    private function byPriority(RedactedGroup $a, RedactedGroup $b): int
    {
        return self::shielded($b) <=> self::shielded($a) // a log group at Error or above is never displaced by a Sentry or metric group (spec 009)
            ?: self::rank($b) <=> self::rank($a)
            ?: $b->count <=> $a->count
            ?: strcmp(hash('sha256', $this->s->tieSalt . $a->fingerprint), hash('sha256', $this->s->tieSalt . $b->fingerprint));
    }

    private static function shielded(RedactedGroup $g): int
    {
        return $g->watchMatches !== [] || (!Tier::isExtra($g->fingerprint) && $g->level->atLeast(Level::Error)) ? 1 : 0;
    }

    private static function rank(RedactedGroup $g): int
    {
        return ($g->watchMatches !== [] ? 1 : 0) * 10_000 + $g->level->value * 10 + match ($g->status) {
            GroupStatusLabel::Grown => 2,
            GroupStatusLabel::Unchanged, GroupStatusLabel::Expected => 1,
            GroupStatusLabel::New => 0,
        };
    }

    /**
     * The first top_m by priority, but new groups take at most half of the slots while older ones wait (leftover slots still go to them).
     *
     * @param list<RedactedGroup> $sorted
     *
     * @return array{0: list<RedactedGroup>, 1: int} the groups to analyse and how many new groups the cap kept out
     */
    private function pickTop(array $sorted): array
    {
        $cap = max(1, intdiv($this->s->topM + 1, 2));
        $top = $held = [];
        $new = 0;
        foreach ($sorted as $g) {
            if (count($top) >= $this->s->topM) {
                break;
            }
            if ($g->status === GroupStatusLabel::New && $new >= $cap) {
                $held[] = $g;
                continue;
            }
            $top[] = $g;
            $new += $g->status === GroupStatusLabel::New ? 1 : 0;
        }
        foreach ($held as $g) {
            if (count($top) >= $this->s->topM) {
                break;
            }
            $top[] = $g;
        }
        $overflow = 0;
        $displaced = array_filter($top, static fn (RedactedGroup $g): bool => $g->status !== GroupStatusLabel::New) !== [];
        foreach ($displaced ? $held : [] as $g) {
            $overflow += in_array($g, $top, true) ? 0 : 1;
        }
        usort($top, $this->byPriority(...));

        return [$top, $overflow];
    }
}
