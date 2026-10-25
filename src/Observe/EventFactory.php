<?php

declare(strict_types=1);

namespace Watcher\Observe;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Watcher\Analyze\Finding;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunSummaryData;
use Watcher\Privacy\RedactedGroup;
use Watcher\RunOutcome;

/**
 * Builds the events of one run from typed objects (never raw log events). One factory per run: it owns the run id and
 * the event counter. Deterministic apart from ts, run_id and event_id.
 */
final class EventFactory
{
    private const MAX_FINGERPRINTS = 5;

    private const MAX_WATCH_RULES = 10;

    private readonly string $runId;

    private int $seq = 0;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly string $version,
        private readonly int $topGroups = 20,
    ) {
        $this->runId = bin2hex(random_bytes(8));
    }

    /**
     * @param ?HeartbeatStatus $heartbeat null when heartbeat.enabled is false (no heartbeat event)
     *
     * @return list<Event>
     */
    public function build(RunObservation $obs, RunSummaryData $d, ?HeartbeatStatus $heartbeat): array
    {
        if (in_array($d->outcome, [RunOutcome::SkippedNotDue, RunOutcome::InvalidConfig, RunOutcome::Disabled], true)) {
            return [];
        }
        $env = $this->envelope($d);
        $events = [$this->event('run', $env, $this->run($obs, $d))];
        if (in_array($d->outcome, [RunOutcome::SkippedDone, RunOutcome::SkippedLocked], true)) {
            return $events;
        }
        foreach ($obs->findings as $i => $finding) {
            $events[] = $this->finding($env, $obs, $finding, $i + 1);
        }
        foreach ($this->top($obs) as $i => $group) {
            $events[] = $this->group($env, $obs, $group, $i + 1);
        }
        if ($obs->spentTodayUsd !== null) {
            $events[] = $this->event('budget', $env, $this->budget($obs, $d));
        }
        if ($heartbeat !== null) {
            $events[] = $this->event('heartbeat', $env, $this->heartbeat($obs, $heartbeat));
        }
        foreach ($obs->deliveryFailures as $failure) {
            $events[] = $this->event('delivery_failure', $env, $failure);
        }
        foreach ($obs->warnings as $warning) {
            $events[] = $this->event('config_warning', $env, $warning);
        }

        return $events;
    }

    /** The probe `watcher check --post-test` flushes to every sink: a config_warning with code check_test. */
    public function checkTest(string $service, ?string $environment): Event
    {
        $env = $this->envelope(new RunSummaryData(RunOutcome::Quiet, $service, environment: $environment));

        return $this->event('config_warning', $env, ['code' => 'check_test', 'key' => null, 'count' => null]);
    }

    /** @return array<string, mixed> */
    private function envelope(RunSummaryData $d): array
    {
        return [
            'schema' => EventSchema::ID,
            'ts' => self::time($this->clock->now()),
            'service' => $d->service ?? 'unknown',
            'environment' => $d->environment ?? 'unset',
            'run_id' => $this->runId,
            'version' => $this->version,
            'window_start' => self::time($d->windowStart),
            'window_end' => self::time($d->windowEnd),
        ];
    }

    /**
     * @param array<string, mixed> $env
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $text
     */
    private function event(string $type, array $env, array $fields, array $text = []): Event
    {
        ++$this->seq;

        return new Event($type, ['event_type' => $type, 'event_id' => $this->runId . '-' . $this->seq] + $env + $fields, $text);
    }

    /** @return array<string, mixed> */
    private function run(RunObservation $obs, RunSummaryData $d): array
    {
        $s = $d->stats;
        $severity = array_fill_keys(EventSchema::SEVERITIES, 0);
        foreach ($obs->findings as $f) {
            ++$severity[$f->severity->value];
        }
        $trend = array_fill_keys(EventSchema::TRENDS, 0);
        if ($obs->trends !== null) {
            $trend = array_replace($trend, array_intersect_key($obs->trends, $trend));
        } else {
            foreach ($obs->groups as $g) {
                ++$trend[$g->status->value];
            }
        }
        $r = $obs->router;

        return [
            'outcome' => $d->outcome->value,
            'reason' => JsonSummary::reason($d->reason),
            'dry_run' => $d->dryRun,
            'release' => $d->release,
            'duration_ms' => $s?->durationMs,
            'events_read' => $s?->eventsRead,
            'groups_total' => $s?->groups,
            'candidates' => $s?->candidates,
            'findings_total' => $s?->findings,
            'findings_by_severity' => $severity,
            'groups_by_trend' => $trend,
            'calls' => $s->calls ?? ['triage' => 0, 'analyze' => 0, 'escalate' => 0],
            'tokens_in' => $s->tokensIn ?? 0,
            'tokens_out' => $s->tokensOut ?? 0,
            'tokens_cached' => $s->tokensCached ?? 0,
            'cost_usd' => $s->costUsd ?? 0.0,
            'escalations' => $r->escalations ?? 0,
            'reused' => $r->reused ?? 0,
            'not_analysed' => $r->notAnalysed ?? 0,
            'muted' => $obs->muted,
            'truncated' => $obs->truncated,
        ];
    }

    /** @param array<string, mixed> $env */
    private function finding(array $env, RunObservation $obs, Finding $f, int $index): Event
    {
        $group = $obs->groups[$f->fingerprints[0]] ?? null;

        return $this->event('finding', $env, [
            'finding_index' => $index,
            'fingerprint' => $f->fingerprints[0],
            'fingerprints' => array_slice($f->fingerprints, 0, self::MAX_FINGERPRINTS),
            'kind' => $f->kind->value,
            'severity' => $f->severity->value,
            'confidence' => $f->confidence,
            'escalated' => $f->escalated,
            'group_trend' => $group?->status->value,
            'group_count' => $group?->count,
            'group_previous_count' => $group?->previousCount,
            'after_release' => in_array($f->fingerprints[0], $obs->newAfterRelease, true),
        ], [
            'title' => $f->title,
            'evidence' => $f->evidence,
            'hypothesis' => $f->suspectedCause,
            'next_step' => $f->suggestedAction,
            'check' => $f->howToCheck,
            'related' => $f->relatedChecks,
        ]);
    }

    /** @return list<RedactedGroup> by count descending, then fingerprint ascending, cut to top_groups */
    private function top(RunObservation $obs): array
    {
        $groups = array_values($obs->groups);
        usort($groups, static fn (RedactedGroup $a, RedactedGroup $b): int => [$b->count, $a->fingerprint] <=> [$a->count, $b->fingerprint]);

        return array_slice($groups, 0, $this->topGroups);
    }

    /** @param array<string, mixed> $env */
    private function group(array $env, RunObservation $obs, RedactedGroup $g, int $rank): Event
    {
        $hasFinding = false;
        foreach ($obs->findings as $f) {
            $hasFinding = $hasFinding || in_array($g->fingerprint, $f->fingerprints, true);
        }

        return $this->event('group', $env, [
            'rank' => $rank,
            'fingerprint' => $g->fingerprint,
            'level' => strtolower($g->level->name),
            'trend' => $g->status->value,
            'count' => $g->count,
            'previous_count' => $g->previousCount,
            'first_seen' => self::time($g->firstSeen),
            'last_seen' => self::time($g->lastSeen),
            'has_finding' => $hasFinding,
            'watch_rules' => array_slice($g->watchMatches, 0, self::MAX_WATCH_RULES),
        ], ['error_type' => $g->errorType]);
    }

    /** @return array<string, mixed> */
    private function budget(RunObservation $obs, RunSummaryData $d): array
    {
        $usd = $obs->dailyBudgetUsd !== null && $obs->dailyBudgetUsd > 0 ? (float) $obs->spentTodayUsd / $obs->dailyBudgetUsd : null;
        $tokens = $obs->dailyTokenLimit !== null && $obs->dailyTokenLimit > 0 ? (float) $obs->tokensToday / $obs->dailyTokenLimit : null;
        $worst = max($usd ?? 0.0, $tokens ?? 0.0);
        $state = $obs->triageOnly || $worst >= 1.0 ? 'exhausted' : ($worst >= 0.9 ? 'high' : 'ok');

        return [
            'state' => $state,
            'run_cost_usd' => $d->stats->costUsd ?? 0.0,
            'spent_today_usd' => $obs->spentTodayUsd,
            'daily_budget_usd' => $obs->dailyBudgetUsd,
            'usd_ratio' => $usd,
            'tokens_today' => $obs->tokensToday,
            'daily_token_limit' => $obs->dailyTokenLimit,
            'token_ratio' => $tokens,
            'threshold_pct' => $state === 'exhausted' ? 100 : $obs->thresholdPct,
            'triage_only' => $obs->triageOnly,
        ];
    }

    /** @return array<string, mixed> */
    private function heartbeat(RunObservation $obs, HeartbeatStatus $h): array
    {
        return [
            'state' => $h->state,
            'last_outcome' => is_string($h->record['outcome'] ?? null) ? $h->record['outcome'] : null,
            'last_ok_at' => self::time($h->comparedWith),
            'age_seconds' => $h->ageSeconds,
            'stale_after_seconds' => $h->limitSeconds,
            'warnings' => array_map(static fn (string $sink): string => 'sink_failed:' . $sink, $obs->priorSinkFailures),
        ];
    }

    private static function time(?DateTimeInterface $t): ?string
    {
        return $t === null ? null : DateTimeImmutable::createFromInterface($t)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
