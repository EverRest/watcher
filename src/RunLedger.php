<?php

declare(strict_types=1);

namespace Watcher;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Watcher\Analyze\Usage;
use Watcher\Config\WatchConfig;
use Watcher\Context\DeployInfo;
use Watcher\Notify\NoticeRenderer;
use Watcher\Notify\Notifier;
use Watcher\Observe\RunObservation;
use Watcher\Observe\RunPublisher;
use Watcher\Privacy\RedactedGroup;
use Watcher\Reduce\EventCollector;
use Watcher\Operate\Heartbeat;
use Watcher\Operate\RunStats;
use Watcher\Operate\RunSummaryData;
use Watcher\Reduce\ProblemGroup;
use Watcher\Schedule\Window;
use Watcher\State\BufferedStateStore;
use Watcher\State\Keys;
use Watcher\State\StateStore;
use Watcher\State\UnreadableCounter;

/**
 * @internal Watcher's own collaborator, not part of the package API.
 *
 * What one run leaves behind: the counters, the window snapshot, the run record, the liveness record and the summary data.
 * Holds the per-run bookkeeping (reset by Watcher::run()) so those five writes live in one place instead of at every exit.
 */
final class RunLedger
{
    private RunStats $stats;

    /** What the event sinks are built from (spec 013); filled only when a publisher is configured. */
    private RunObservation $observation;

    private ?Window $window = null;

    /** Short reason code for the JSON summary. */
    private ?string $code = null;

    private ?float $todayUsd = null;

    /** True once the trigger passed the run_at gate: only then does a heartbeat get written. */
    private bool $due = false;

    private ?DeployInfo $deployInfo = null;

    private DateTimeImmutable $startedAt;

    /** Dependency snapshots wait here and are written together with the window snapshot, so a failed run leaves none. */
    private ?BufferedStateStore $pending = null;

    public function __construct(
        private readonly WatchConfig $config,
        private readonly StateStore $state,
        private readonly ClockInterface $clock,
        private readonly ?Heartbeat $heartbeat,
        private readonly Notifier $notifier,
        private readonly ?LoggerInterface $hostLog,
        private readonly ?RunPublisher $publisher = null,
        private readonly int $topGroups = 20,
    ) {
        $this->reset();
    }

    public function reset(): void
    {
        $this->stats = new RunStats();
        $this->observation = new RunObservation();
        $this->window = null;
        $this->code = null;
        $this->todayUsd = null;
        $this->due = false;
        $this->deployInfo = null;
        $this->startedAt = $this->clock->now();
    }

    public function stats(): RunStats
    {
        return $this->stats;
    }

    public function observation(): RunObservation
    {
        return $this->observation;
    }

    /** Whether the events are wanted at all: with no sink the run collects nothing for them. */
    public function observing(): bool
    {
        return $this->publisher !== null;
    }

    /**
     * What the read left, as counts and the top groups only: trends of every kept group, and the top_groups biggest
     * as RedactedGroup (allowlisted fields; the template is never read for an event).
     *
     * @param list<ProblemGroup> $groups the groups after muting, comparison and watch rules
     */
    public function observeRead(array $groups, EventCollector $collector, int $muted): void
    {
        if ($this->publisher === null) {
            return;
        }
        $o = $this->observation;
        $o->trends = [];
        foreach ($groups as $g) {
            $o->trends[$g->status->value] = ($o->trends[$g->status->value] ?? 0) + 1;
        }
        usort($groups, static fn (ProblemGroup $a, ProblemGroup $b): int => [$b->count, $a->fingerprint] <=> [$a->count, $b->fingerprint]);
        foreach (array_slice($groups, 0, $this->topGroups) as $g) {
            $o->groups[$g->fingerprint] = RedactedGroup::fromProblemGroup($g);
        }
        $o->muted = $muted;
        $o->truncated = $collector->truncated();
        if ($collector->truncationReason() !== null) {
            $o->warn('event_cap_hit', 'source.event_cap', $collector->eventsRead());
        }
        if ($collector->overflowedEvents() > 0) {
            $o->warn('group_cap_hit', 'source.group_cap', $collector->overflowedEvents());
        }
    }

    /** The model run's findings and spend, for the finding, group and budget events. */
    public function observeAnalysis(Analysis $a, bool $triageOnly): void
    {
        if ($this->publisher === null) {
            return;
        }
        $o = $this->observation;
        $o->findings = $a->routed->findings;
        $o->router = $a->routed;
        foreach ($a->byFingerprint as $fp => $group) {
            $o->groups[$fp] = $group;
        }
        $cfg = $this->config->analysis;
        $o->spentTodayUsd = $a->after['usd'];
        $o->tokensToday = $a->after['tokens'];
        $o->dailyBudgetUsd = is_numeric($cfg['daily_budget_usd'] ?? null) ? (float) $cfg['daily_budget_usd'] : null;
        $o->dailyTokenLimit = is_numeric($cfg['daily_token_limit'] ?? null) ? (int) $cfg['daily_token_limit'] : null;
        $o->triageOnly = $triageOnly;
        $ratio = max(
            $o->dailyBudgetUsd > 0 ? $o->spentTodayUsd / $o->dailyBudgetUsd : 0.0,
            $o->dailyTokenLimit > 0 ? $o->tokensToday / $o->dailyTokenLimit : 0.0,
        );
        foreach ($cfg['budget_alert_percents'] ?? [] as $percent) {
            if (is_int($percent) && $ratio * 100 >= $percent) {
                $o->thresholdPct = max($o->thresholdPct, $percent);
            }
        }
        $notDone = $a->routed->notAnalysed + $a->routed->notTriaged;
        if ($notDone > 0) {
            $o->warn('analysis_partial', null, $notDone);
        }
    }

    /**
     * Flushes the events to the sinks (after finish(), so the heartbeat status is the fresh one) and returns the summary with
     * the run id and the failed sinks. Never throws and never changes the outcome.
     */
    public function publish(RunSummaryData $summary, ?int $remainingSeconds, bool $dryRun, callable $out): RunSummaryData
    {
        if ($this->publisher === null) {
            return $summary;
        }
        $status = $this->heartbeat?->status($this->config->heartbeat['stale_after']);
        $done = $this->publisher->publish($this->observation, $summary, $status, $remainingSeconds, $dryRun, $out);

        return $done === null ? $summary : $summary->withSinks($done['run_id'], $done['failures']);
    }

    /** The trigger passed the run_at gate for this window. */
    public function started(Window $window): void
    {
        $this->window = $window;
        $this->due = true;
    }

    public function code(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    public function setTodayUsd(float $usd): void
    {
        $this->todayUsd = $usd;
    }

    public function setDeployInfo(DeployInfo $deploy): void
    {
        $this->deployInfo = $deploy;
    }

    /** Starts holding writes back (dependency snapshots) until snapshot() flushes them. */
    public function defer(): void
    {
        $this->pending = new BufferedStateStore($this->state);
    }

    /** Where dependency snapshots go: the held-back buffer when there is one. */
    public function deferred(): StateStore
    {
        return $this->pending ?? $this->state;
    }

    /**
     * @param list<ProblemGroup> $groups
     * @param array<string, int> $totals
     */
    public function snapshot(Window $window, array $groups, array $totals): void
    {
        $counts = [];
        foreach ($groups as $g) {
            $counts[$g->fingerprint] = $g->count;
        }
        $this->pending?->flush();
        $key = Watcher::windowKey($window->end);
        $this->state->put(
            Keys::snapshot($this->config->stateKey, $key),
            ['groups' => $counts, ...($this->config->on('level_anomaly') ? ['totals' => $totals] : []), 'deploy' => $this->deployInfo?->toSnapshot(), 'start' => Watcher::windowKey($window->start)],
            $this->config->state['retention'],
        );
        $this->state->put(Keys::lastSnapshot($this->config->stateKey), ['end' => $key], $this->config->state['retention']);
    }

    /** The run record: its presence is what makes the next trigger for the same window a "skipped_done". */
    public function runRecord(string $key, RunOutcome $outcome, ?Usage $usage, DateTimeImmutable $started, ?string $code = null): void
    {
        $this->state->put($key, [
            'outcome' => $outcome->value,
            ...($code === null ? [] : ['code' => $code]),
            'usage' => $usage === null ? null : get_object_vars($usage),
            'startedAt' => $started->format(DATE_ATOM),
            'finishedAt' => $this->clock->now()->format(DATE_ATOM),
        ], $this->config->state['retention']);
    }

    /**
     * Last bookkeeping of every run: duration, the liveness record and the weekly line.
     * Nothing here can change the outcome (FR-005); a dry run has no heartbeat.
     */
    public function finish(RunOutcome $outcome, NoticeRenderer $notices, string $environment): void
    {
        $took = (float) $this->clock->now()->format('U.u') - (float) $this->startedAt->format('U.u');
        $this->stats->durationMs = max(0, (int) round($took * 1000));
        $this->stats->stateUnreadable = $this->state instanceof UnreadableCounter ? $this->state->unreadableEntries() : 0;
        if ($this->heartbeat === null || !$this->due) {
            return;
        }
        try {
            if ($this->window !== null && in_array($outcome, [RunOutcome::Reported, RunOutcome::Quiet, RunOutcome::Baselined, RunOutcome::Failed], true)) {
                $this->heartbeat->recordRun($outcome, $this->window->start, $this->window->end, $this->startedAt, $this->stats->costUsd, $this->code, $this->stats->events['status'] ?? null);
            } else {
                $this->heartbeat->recordSkip($outcome);
            }
            if ($this->config->heartbeat['weekly_summary'] && $this->config->on('notice')) {
                $this->heartbeat->sendWeeklySummary($this->notifier, $this->config->destination('notice'), $notices, $environment);
            }
        } catch (Throwable $e) {
            $this->hostLog?->warning('heartbeat failed: ' . $e::class, ['service' => $this->config->service]);
        }
    }

    /** Counts of a skipped trigger are not reported: nothing was read. */
    public function summary(RunOutcome $outcome, bool $dryRun): RunSummaryData
    {
        $skipped = in_array($outcome, [RunOutcome::SkippedNotDue, RunOutcome::SkippedDone, RunOutcome::SkippedLocked], true);

        return new RunSummaryData(
            $outcome,
            $this->config->service,
            $this->code,
            $this->window?->start,
            $this->window?->end,
            $skipped ? null : $this->stats,
            $this->todayUsd,
            $this->deployInfo?->release,
            $dryRun,
            $this->config->environment,
        );
    }
}
