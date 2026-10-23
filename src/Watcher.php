<?php

declare(strict_types=1);

namespace Watcher;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Budget;
use Watcher\Analyze\BudgetAlerts;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\ModelRouter;
use Watcher\Analyze\RouterSettings;
use Watcher\Analyze\Spend;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\Usage;
use Watcher\Analyze\VerdictCache;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\DigestData;
use Watcher\Notify\DigestRenderer;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Fingerprinter;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;
use Watcher\Reduce\Reducer;
use Watcher\Schedule\RunGate;
use Watcher\Schedule\SystemClock;
use Watcher\Schedule\Window;
use Watcher\Select\LevelAnomaly;
use Watcher\Select\MatcherParser;
use Watcher\Select\MuteList;
use Watcher\Select\WatchRules;
use Watcher\Select\Selector;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/** Single entry point (FR-033). US1 pipeline: no run_at gate, triage, budgets, watch rules, dependencies or deploy info yet. */
final class Watcher
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly WatchConfig $config,
        private readonly LogSource $source,
        private readonly StateStore $state,
        private readonly Analyzer $analyzer,
        private readonly Notifier $notifier,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $hostLog = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param array<string, mixed> $config
     * @throws Config\InvalidConfig before any external call; callers map it to RunResult::invalidConfig()
     */
    public static function fromConfig(array $config, ?LoggerInterface $hostLog = null, ?ClockInterface $clock = null): self
    {
        $c = WatchConfig::fromArray($config);
        $clock ??= new SystemClock();
        $http = Factory::http();

        return new self(
            $c,
            Factory::source($c, $http),
            Factory::state($c, $clock),
            Factory::analyzer($c, $http),
            Factory::notifier($http),
            $clock,
            $hostLog,
        );
    }

    /** State-key component for a window end (UTC, second precision). */
    public static function windowKey(DateTimeImmutable $end): string
    {
        return $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function run(?DateTimeImmutable $now = null): RunResult
    {
        $c = $this->config;
        $now ??= $this->clock->now();
        // Before any external call and before the lock: a trigger outside the run_at hour costs nothing (FR-002).
        $window = (new RunGate($c->schedule['run_at'], new \DateTimeZone($c->schedule['timezone']), $c->schedule['window']))->decide($now);
        if ($window === null) {
            return new RunResult(RunOutcome::SkippedNotDue);
        }
        $service = $c->service;
        $owner = bin2hex(random_bytes(8));
        $retention = $c->state['retention'];
        $lockKey = Keys::lock($service, self::windowKey($window->end));

        try {
            if (!$this->state->acquire($lockKey, $owner, Keys::ttl(Keys::LOCK, $retention, $c->schedule['lock_ttl']))) {
                return new RunResult(RunOutcome::SkippedLocked);
            }

            return $this->locked($window);
        } catch (SourceUnavailable|AnalyzerFailed|DeliveryFailed $e) {
            // US5: post a user-visible "source/analysis/delivery failed" notice here.
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            // State and unexpected faults: class name only, never a message or chain that might carry a secret.
            return $this->fail('internal error (' . $e::class . ')');
        } finally {
            try {
                $this->state->release($lockKey, $owner);
            } catch (Throwable) {
                // The lock expires by TTL; nothing more to do.
            }
        }
    }

    private function fail(string $reason): RunResult
    {
        $this->hostLog?->error('watcher run failed: ' . $reason, ['service' => $this->config->service]);

        return new RunResult(RunOutcome::Failed, $reason);
    }

    private function locked(Window $window): RunResult
    {
        $c = $this->config;
        $service = $c->service;
        $retention = $c->state['retention'];
        $endKey = self::windowKey($window->end);
        $runKey = Keys::run($service, $endKey);
        $started = $this->clock->now();

        if ($this->state->get($runKey) !== null) {
            return new RunResult(RunOutcome::SkippedDone);
        }

        // Built before any external call so a bad configuration fails at start, not mid-run.
        $normalizer = new Normalizer(array_map('strval', array_keys($c->dependencies)), $c->dependencyDomains);
        $redactor = new Redactor($normalizer, $c->redactionPatterns);

        $selector = new StreamSelector($c->source['stream_tags']);
        $readMin = $c->source['read_min_level'];
        $totals = $this->source->totals($window, $selector);

        /** @var array{groups?: array<string, int>, totals?: array<string, int>}|null $prev */
        $prev = $this->state->get(Keys::snapshot($service, self::windowKey($window->start)));
        $anomalies = LevelAnomaly::levels($prev === null ? null : ($prev['totals'] ?? []), $totals, $c->selection['growth_factor'], $readMin);

        $truncated = false;
        $rules = new WatchRules($c->watchRules);
        $mute = new MuteList($c->mute);
        $muteMatchers = [];
        foreach ($c->mute as $i => $entry) {
            if (!isset($entry['fingerprint'])) {
                $m = MatcherParser::parse($entry);
                if ($m->isFieldMatcher()) {
                    $muteMatchers[$i] = $m;
                }
            }
        }
        $fingerprinter = new Fingerprinter($normalizer);
        /** @var array<string, list<string>> $ruleHits rule names per fingerprint */
        $ruleHits = [];
        /** @var array<string, list<string>> $muteHits mute entry indexes per fingerprint */
        $muteHits = [];
        $events = (function () use ($window, $selector, $anomalies, $readMin, &$truncated, $rules, $muteMatchers, $fingerprinter, &$ruleHits, &$muteHits): iterable {
            // Field VALUES are matched here and dropped: only rule names and mute indexes are kept per fingerprint.
            $observe = static function (\Watcher\Source\LogEvent $e) use ($rules, $muteMatchers, $fingerprinter, &$ruleHits, &$muteHits): \Watcher\Source\LogEvent {
                $names = $rules->namesMatchingEvent($e);
                $hits = [];
                foreach ($muteMatchers as $i => $m) {
                    if ($m->matchesEvent($e)) {
                        $hits[] = (string) $i;
                    }
                }
                if ($names !== [] || $hits !== []) {
                    $fp = $fingerprinter->of($e);
                    $ruleHits[$fp] = array_values(array_unique([...($ruleHits[$fp] ?? []), ...$names]));
                    $muteHits[$fp] = array_values(array_unique([...($muteHits[$fp] ?? []), ...$hits]));
                }

                return $e;
            };
            foreach ($anomalies as $level) {
                foreach ($this->source->lines($window, $selector, new ReadFilter($level, [], $this->config->source['anomaly_cap'])) as $e) {
                    if ($e->level === $level) {
                        yield $observe($e);
                    }
                }
            }
            foreach ($this->source->lines($window, $selector, new ReadFilter($readMin, [], $this->config->source['event_cap'])) as $e) {
                yield $observe($e);
            }
            $truncated = $this->source->lastReadTruncated();
        })();

        $groups = (new Reducer($normalizer, $c->minGroupEventsForText))->reduce($events);
        // Muted groups leave first, so they reach neither a model nor the selector; the snapshot keeps them for growth.
        $allGroups = $groups;
        $muteResult = $mute->filter($groups, $muteHits, $started);
        $groups = $muteResult->kept;
        $factor = $c->selection['growth_factor'];
        $previousGroups = $prev['groups'] ?? [];
        $groups = array_map(
            static fn (ProblemGroup $g): ProblemGroup => $g->withPrevious($prev === null ? null : ($previousGroups[$g->fingerprint] ?? null), $factor),
            $groups,
        );

        $groups = $rules->apply($groups, $ruleHits);
        $environment = is_string($c->source['stream_tags']['env'] ?? null) ? $c->source['stream_tags']['env'] : 'default';
        $renderer = new DigestRenderer();

        if ($prev === null) {
            $this->writeSnapshot($window, $allGroups, $totals);
            $this->notifier->send(
                $renderer->renderBaseline($service, $environment, count($groups), array_sum(array_map(static fn (ProblemGroup $g): int => $g->count, $groups))),
                new Destination($c->notify['notice']['webhook']),
            );
            $this->writeRun($runKey, RunOutcome::Baselined, null, $started);

            return new RunResult(RunOutcome::Baselined);
        }

        $selection = (new Selector())->select($groups, $c->selection['min_level'], $c->selection['top_n'], $anomalies);
        $candidates = [];
        foreach ($selection->candidates as $cand) {
            $reported = $this->state->get(Keys::reported($service, $cand->group->fingerprint));
            if ($reported === null || $cand->group->status === GroupStatus::Grown) {
                $candidates[] = $cand;
            }
        }

        if ($candidates === []) {
            $this->writeSnapshot($window, $allGroups, $totals);
            $this->writeRun($runKey, RunOutcome::Quiet, null, $started);

            return new RunResult(RunOutcome::Quiet);
        }

        // Truncate by candidate priority first, so the lowest-priority groups are the ones dropped (FR-021).
        $redactedBy = [];
        foreach ($candidates as $cand) {
            $redactedBy[$cand->group->fingerprint] = $redactor->redact($cand->group);
        }
        $truncation = Budget::truncate(
            $candidates,
            $c->analysis['max_input_tokens']['triage'],
            static fn (array $cs): string => CompactPayload::encode(array_map(static fn ($cand) => $redactedBy[$cand->group->fingerprint], $cs)),
        );
        $redacted = array_map(static fn ($cand) => $redactedBy[$cand->group->fingerprint], $truncation->kept);

        $tz = new \DateTimeZone($c->schedule['timezone']);
        $spend = new Spend($this->state, $this->clock, $tz, $service);
        $notice = new Destination($c->notify['notice']['webhook']);
        $triageOnly = $spend->exhausted($c->analysis['daily_budget_usd'], $c->analysis['daily_token_limit']);

        $before = $spend->today();
        $router = new ModelRouter(self::redacting($this->analyzer, $redactor), new VerdictCache($this->state, $service, $retention), $this->routerSettings());
        $routed = $router->route($redacted, $triageOnly); // AnalyzerFailed ends the run; US5 adds the user-visible notice

        $calculator = new CostCalculator($c->analysis['prices']);
        $usage = null;
        $costUsd = 0.0;
        $models = [];
        foreach ($routed->usageByStep as $step => $u) {
            $cost = $calculator->cost($u);
            $spend->add($u, $cost);
            $costUsd += $cost;
            $models[$step] = $c->analysis['models'][$step];
            $priced = new Usage($u->model, $u->inputTokens, $u->outputTokens, $u->cacheReadTokens, $u->cacheWriteTokens, $cost);
            $usage = $usage === null ? $priced : $usage->plus($priced);
        }
        $after = $spend->today();

        if ($triageOnly && $routed->triaged > 0) {
            $this->noticeOnce('exhausted:' . rawurlencode($service) . ':' . $spend->day(), 'budget exhausted: triage only today', $notice);
        }
        $alert = (new BudgetAlerts($this->state, $this->clock, $tz, $service))->check(
            $before,
            $after,
            $c->analysis['daily_budget_usd'],
            $c->analysis['daily_token_limit'],
            $c->analysis['budget_alert_percents'],
        );
        if ($alert !== null) {
            $this->notifier->send(self::plainNotice($alert->noticeText($service)), $notice);
        }

        $findings = $routed->findings; // already scrubbed by the decorator, before the router cached them
        $dropped = $truncation->droppedCount + $routed->droppedByTopM;

        if ($findings === [] && $dropped === 0) {
            $this->writeSnapshot($window, $allGroups, $totals);
            $this->writeRun($runKey, RunOutcome::Quiet, $usage, $started);

            return new RunResult(RunOutcome::Quiet, null, $usage);
        }

        $byFingerprint = [];
        foreach ($redacted as $g) {
            $byFingerprint[$g->fingerprint] = $g;
        }
        $message = $renderer->render(new DigestData(
            $service,
            $environment,
            $window->start,
            $window->end,
            $c->schedule['timezone'],
            $findings,
            $byFingerprint,
            null,
            $usage,
            $models,
            $costUsd,
            $this->anomalyLine($anomalies, $prev['totals'] ?? [], $totals),
            $truncated ? array_sum(array_map(static fn (ProblemGroup $g): int => $g->count, $groups)) : null,
            $dropped,
            $selection->otherGroups,
            $selection->otherEvents,
            $muteResult->mutedCount,
            $after['usd'],
            $c->analysis['daily_budget_usd'],
            $after['tokens'],
            $c->analysis['daily_token_limit'],
        ), $c->analysis['confidence_threshold']);
        $this->notifier->send($message, new Destination($c->notify['digest']['webhook']));

        $now = $this->clock->now()->format(DATE_ATOM);
        foreach ($findings as $finding) {
            foreach ($finding->fingerprints as $fp) {
                $this->state->put(Keys::reported($service, $fp), ['lastReportedAt' => $now, 'atCount' => $byFingerprint[$fp]->count ?? 0], $retention);
            }
        }
        $this->writeSnapshot($window, $allGroups, $totals);
        $this->writeRun($runKey, RunOutcome::Reported, $usage, $started);

        return new RunResult(RunOutcome::Reported, null, $usage);
    }

    /** Model output is scrubbed at the boundary, so neither the verdict cache nor the digest ever sees the raw text (Principle I). */
    private static function redacting(Analyzer $inner, Redactor $redactor): Analyzer
    {
        return new class ($inner, $redactor) implements Analyzer {
            public function __construct(private readonly Analyzer $inner, private readonly Redactor $redactor)
            {
            }

            public function triage(TriageRequest $req): TriageResult
            {
                return $this->inner->triage($req);
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                $r = $this->inner->analyze($req);

                return new AnalysisResult(array_map($this->redactor->redactFinding(...), $r->findings), $r->usage);
            }
        };
    }

    private function routerSettings(): RouterSettings
    {
        $a = $this->config->analysis;

        return new RouterSettings(
            $a['models']['triage'],
            $a['models']['analyze'],
            $a['models']['escalate'],
            $a['max_output_tokens']['triage'],
            $a['max_output_tokens']['analyze'],
            $a['max_output_tokens']['escalate'],
            $this->config->selection['top_m'],
            $a['escalation_cap'],
            $a['confidence_threshold'],
            $this->config->selection['growth_factor'],
            $this->config->serviceContext,
        );
    }

    private static function plainNotice(string $text): Message
    {
        return new Message($text, [['kind' => 'notice', 'text' => $text]]);
    }

    /** Sends once per key (the key lives two days); a failed send gives the key back so the next run retries. */
    private function noticeOnce(string $key, string $text, Destination $to): void
    {
        $owner = bin2hex(random_bytes(8));
        if (!$this->state->acquire($key, $owner, Keys::ttl(Keys::SPEND, 0, 0))) {
            return;
        }
        try {
            $this->notifier->send(self::plainNotice($text), $to);
        } catch (Throwable $e) {
            $this->state->release($key, $owner);

            throw $e;
        }
    }

    /**
     * @param list<ProblemGroup> $groups
     * @param array<string, int> $totals
     */
    private function writeSnapshot(Window $window, array $groups, array $totals): void
    {
        $counts = [];
        foreach ($groups as $g) {
            $counts[$g->fingerprint] = $g->count;
        }
        $this->state->put(
            Keys::snapshot($this->config->service, self::windowKey($window->end)),
            ['groups' => $counts, 'totals' => $totals],
            $this->config->state['retention'],
        );
    }

    private function writeRun(string $key, RunOutcome $outcome, ?Usage $usage, DateTimeImmutable $started): void
    {
        $this->state->put($key, [
            'outcome' => $outcome->value,
            'usage' => $usage === null ? null : get_object_vars($usage),
            'startedAt' => $started->format(DATE_ATOM),
            'finishedAt' => $this->clock->now()->format(DATE_ATOM),
        ], $this->config->state['retention']);
    }

    /**
     * @param list<Level>        $levels
     * @param array<string, int> $previous
     * @param array<string, int> $current
     */
    private function anomalyLine(array $levels, array $previous, array $current): ?string
    {
        $parts = [];
        foreach ($levels as $level) {
            $name = strtolower($level->name);
            $was = $previous[$name] ?? 0;
            $parts[] = $was === 0
                ? "$name appeared"
                : sprintf('%s ×%s', $name, rtrim(rtrim(number_format(($current[$name] ?? 0) / $was, 1, '.', ''), '0'), '.'));
        }

        return $parts === [] ? null : implode(', ', $parts) . ' vs previous window';
    }
}
