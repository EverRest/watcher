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
use Watcher\Analyze\UnknownModelPrice;
use Watcher\Analyze\Usage;
use Watcher\Analyze\VerdictCache;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Context\CodeLinkBuilder;
use Watcher\Context\DependencyContext;
use Watcher\Context\DependencyContextReader;
use Watcher\Context\DependencyPlan;
use Watcher\Context\DependencyResolver;
use Watcher\Context\DeployInfo;
use Watcher\Context\LogLinkBuilder;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\DigestData;
use Watcher\Notify\DigestRenderer;
use Watcher\Notify\Message;
use Watcher\Notify\NoticeRenderer;
use Watcher\Notify\SlackText;
use Watcher\Notify\Notifier;
use Watcher\Privacy\RedactedGroup;
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
use Watcher\State\BufferedStateStore;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/** Single entry point (FR-033). US1 pipeline: no run_at gate, triage, budgets, watch rules, dependencies or deploy info yet. */
final class Watcher
{
    private readonly ClockInterface $clock;

    /** Dependency snapshots wait here and are written together with the window snapshot, so a failed run leaves none. */
    private ?BufferedStateStore $pendingWrites = null;

    private ?DeployInfo $deployInfo = null;

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
            Factory::source($c, $http, $clock),
            Factory::state($c, $clock),
            Factory::analyzer($c),
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
        } catch (SourceUnavailable $e) {
            return $this->failWithNotice('source', $e->getMessage());
        } catch (AnalyzerFailed $e) {
            return $this->failWithNotice('analysis', $e->getMessage());
        } catch (DeliveryFailed $e) {
            // The channel that just failed cannot carry the news: host log and exit code only.
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            // State and unexpected faults: class name only, never a message or chain that might carry a secret.
            return $this->failWithNotice('run', 'internal error (' . $e::class . ')');
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

    /** A failed notice must never mask the original failure or crash the run (FR-031). */
    private function failWithNotice(string $kind, string $reason): RunResult
    {
        $result = $this->fail($reason);
        try {
            $this->notifier->send($this->notices()->failure($this->config->service, $this->environment(), $kind, $reason), $this->noticeDestination());
        } catch (Throwable) {
            // Already on the way out with a failure; the host log and exit code carry it.
        }

        return $result;
    }

    /** The digest channel cannot carry its own failure; when the notice channel is another one, it can. */
    private function announceDigestFailure(DeliveryFailed $e, string $environment): void
    {
        $n = $this->config->notify;
        if ($n['notice']['webhook'] === $n['digest']['webhook']) {
            return;
        }
        $code = preg_match('/HTTP (\d{3})/', $e->getMessage(), $m) === 1 ? ': HTTP ' . $m[1] : '';
        $text = sprintf('❌ watcher · %s · %s — digest delivery failed%s', SlackText::escape($this->config->service), SlackText::escape($environment), $code);
        try {
            $this->notifier->send(self::plainNotice($text), $this->noticeDestination());
        } catch (Throwable) {
            // The host log and exit code carry the failure.
        }
    }

    private function notices(): NoticeRenderer
    {
        $c = $this->config;

        return new NoticeRenderer([$c->analysis['api_key'], $c->notify['digest']['webhook'], $c->notify['notice']['webhook']]);
    }

    private function noticeDestination(): Destination
    {
        return new Destination($this->config->notify['notice']['webhook']);
    }

    private function environment(): string
    {
        $env = $this->config->source['stream_tags']['env'] ?? null;

        return is_string($env) ? $env : 'default';
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

        /** @var array{groups?: array<string, int>, totals?: array<string, int>, deploy?: array<string, string|null>}|null $prev */
        $prev = $this->state->get(Keys::snapshot($service, self::windowKey($window->start)));
        $this->pendingWrites = new BufferedStateStore($this->state);
        $deploy = $this->deployInfo = DeployInfo::resolve($c->deploy, $prev['deploy'] ?? null, $this->source, $window, $selector);
        $anomalies = LevelAnomaly::levels($prev === null ? null : ($prev['totals'] ?? []), $totals, $c->selection['growth_factor'], $readMin);

        $truncated = false;
        $eventsRead = 0;
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
        /** @var array<string, array<int, int>> $muteHits fingerprint => [mute entry index => events that matched it] (counts only) */
        $muteHits = [];
        /** @var list<ReadFilter> kept only to read their patternErrors counters */
        $filters = [];
        $ruleMatched = false; // set by observe for the event the reducer is processing right now (the stream is sequential)
        $events = (function () use ($window, $selector, $anomalies, $readMin, &$truncated, &$eventsRead, $rules, $muteMatchers, $fingerprinter, &$ruleHits, &$muteHits, &$ruleMatched, &$filters): iterable {
            // Field VALUES are matched here and dropped: only rule names and mute indexes are kept per fingerprint.
            $observe = static function (\Watcher\Source\LogEvent $e) use ($rules, $muteMatchers, $fingerprinter, &$eventsRead, &$ruleHits, &$muteHits, &$ruleMatched): \Watcher\Source\LogEvent {
                ++$eventsRead; // counted as they stream, so muted events are included
                $names = $rules->namesMatchingEvent($e);
                $ruleMatched = $names !== [];
                $hits = [];
                foreach ($muteMatchers as $i => $m) {
                    if ($m->matchesEvent($e)) {
                        $hits[] = (int) $i;
                    }
                }
                if ($names !== [] || $hits !== []) {
                    $fp = $fingerprinter->of($e);
                    $ruleHits[$fp] = array_values(array_unique([...($ruleHits[$fp] ?? []), ...$names]));
                    foreach ($hits as $i) {
                        $muteHits[$fp][$i] = ($muteHits[$fp][$i] ?? 0) + 1;
                    }
                }

                return $e;
            };
            foreach ($anomalies as $level) {
                $filters[] = $filter = new ReadFilter($level, [], $this->config->source['anomaly_cap']);
                foreach ($this->source->lines($window, $selector, $filter) as $e) {
                    if ($e->level === $level) {
                        yield $observe($e);
                    }
                }
                $truncated = $this->source->lastReadTruncated() || $truncated;
            }
            $filters[] = $filter = new ReadFilter($readMin, $rules->backendPatterns(), $this->config->source['event_cap']);
            foreach ($this->source->lines($window, $selector, $filter) as $e) {
                yield $observe($e);
            }
            $truncated = $this->source->lastReadTruncated() || $truncated;
        })();

        // An event folded into the overflow group drops its hits: they would key a fingerprint that has no group, and a mute must never hide the overflow.
        $reducer = new Reducer(
            $normalizer,
            $c->minGroupEventsForText,
            $c->source['group_cap'],
            static function () use (&$ruleMatched): bool {
                return $ruleMatched;
            },
            static function (\Watcher\Source\LogEvent $e) use ($fingerprinter, &$ruleHits, &$muteHits): void {
                $fp = $fingerprinter->of($e);
                unset($ruleHits[$fp], $muteHits[$fp]);
            },
        );
        $groups = $reducer->reduce($events);
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
        // Counts only: a regex that failed to evaluate (backtrack limit) fails open, and the operator should hear about it.
        $patternFailures = $rules->failures() + $mute->failures()
            + array_sum(array_map(static fn (\Watcher\Select\Matcher $m): int => $m->failures, $muteMatchers))
            + array_sum(array_map(static fn (ReadFilter $f): int => $f->patternErrors, $filters));
        if ($patternFailures > 0) {
            $this->hostLog?->warning('pattern evaluation failures: rules may have been skipped', ['service' => $service, 'count' => $patternFailures]);
        }
        $environment = $this->environment();
        $renderer = new DigestRenderer();

        if ($prev === null) {
            $this->readDependencies($window, $normalizer, $redactor, null);
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
            $this->readDependencies($window, $normalizer, $redactor, null);
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

        [$dependencies, $failedDependencies, $undeclared] = $this->readDependencies($window, $normalizer, $redactor, array_map(static fn (RedactedGroup $g): ?string => $g->template, $redacted));
        $dependencies = self::fitDependencies($redacted, $dependencies, $c->analysis['max_input_tokens']['analyze']);

        $tz = new \DateTimeZone($c->schedule['timezone']);
        $spend = new Spend($this->state, $this->clock, $tz, $service);
        $notice = $this->noticeDestination();
        $triageOnly = $spend->exhausted($c->analysis['daily_budget_usd'], $c->analysis['daily_token_limit']);

        $buffer = new BufferedStateStore($this->state); // verdicts and reported markers: kept only once the digest is delivered
        $before = $spend->today();
        $calculator = new CostCalculator($c->analysis['prices']);
        $router = new ModelRouter(self::metered(self::redacting($this->analyzer, $redactor), $spend, $calculator, $this->hostLog), new VerdictCache($buffer, $service, $retention), $this->routerSettings($this->serviceContext($redactor)));
        try {
            $routed = $router->route($redacted, $triageOnly, $dependencies);
        } catch (AnalyzerFailed $e) {
            // The window was read: keep it as the next window's comparison base, but leave no RunRecord so the next trigger retries.
            $this->writeSnapshot($window, $allGroups, $totals);

            throw $e;
        }

        $usage = null;
        $costUsd = 0.0;
        $models = [];
        foreach ($routed->usageByStep as $step => $u) {
            try {
                $cost = $calculator->cost($u); // already added to Spend per call, see metered()
            } catch (UnknownModelPrice) {
                $cost = 0.0; // booked at 0 and warned about in metered()
            }
            $costUsd += $cost;
            $models[$step] = $c->analysis['models'][$step];
            $priced = new Usage($u->model, $u->inputTokens, $u->outputTokens, $u->cacheReadTokens, $u->cacheWriteTokens, $cost);
            $usage = $usage === null ? $priced : $usage->plus($priced);
        }
        $after = $spend->today();

        if ($triageOnly && $routed->triaged > 0) {
            try {
                $this->noticeOnce('exhausted:' . rawurlencode($service) . ':' . $spend->day(), $this->notices()->budgetExhausted($service, $environment), $notice);
            } catch (Throwable $e) {
                $this->hostLog?->warning('budget notice not delivered: ' . $e->getMessage(), ['service' => $service]);
            }
        }
        $alerts = new BudgetAlerts($this->state, $this->clock, $tz, $service);
        $alert = $alerts->check(
            $before,
            $after,
            $c->analysis['daily_budget_usd'],
            $c->analysis['daily_token_limit'],
            $c->analysis['budget_alert_percents'],
        );
        if ($alert !== null) {
            try {
                $this->notifier->send(self::plainNotice($alert->noticeText($service)), $notice);
            } catch (Throwable $e) {
                $alerts->release($alert);
                $this->hostLog?->warning('budget alert not delivered: ' . $e->getMessage(), ['service' => $service]);
            }
        }

        $findings = $routed->findings; // already scrubbed by the decorator, before the router cached them
        $dropped = $truncation->droppedCount + $routed->droppedByTopM;

        if ($findings === [] && $dropped === 0 && $routed->notAnalysed === 0 && $patternFailures === 0) {
            $buffer->flush(); // settled verdicts are real even when there is nothing to say
            $this->writeSnapshot($window, $allGroups, $totals);
            $this->writeRun($runKey, RunOutcome::Quiet, $usage, $started);

            return new RunResult(RunOutcome::Quiet, null, $usage);
        }

        $byFingerprint = [];
        foreach ($redacted as $g) {
            $byFingerprint[$g->fingerprint] = $g;
        }
        $logLinks = new LogLinkBuilder($c->links, $c->source['stream_tags']);
        $codeLinks = new CodeLinkBuilder($c->repo ?? [], $deploy->commit);
        $links = [];
        $afterRelease = [];
        foreach ($redacted as $g) {
            $links[$g->fingerprint] = ['log' => $logLinks->forGroup($g, $window), 'code' => $codeLinks->forGroup($g)];
            $mark = $deploy->firstSeenAfterLabel($g->status);
            if ($mark !== null) {
                $afterRelease[$g->fingerprint] = $mark;
            }
        }
        $message = $renderer->render(new DigestData(
            $service,
            $environment,
            $window->start,
            $window->end,
            $c->schedule['timezone'],
            $findings,
            $byFingerprint,
            $deploy->release,
            $usage,
            $models,
            $costUsd,
            $this->anomalyLine($anomalies, $prev['totals'] ?? [], $totals),
            $truncated ? $eventsRead : null,
            $truncation->droppedCount,
            $selection->otherGroups,
            $selection->otherEvents,
            $muteResult->mutedCount,
            $after['usd'],
            $c->analysis['daily_budget_usd'],
            $after['tokens'],
            $c->analysis['daily_token_limit'],
            $afterRelease,
            $undeclared,
            $failedDependencies,
            $links,
            $reducer->overflowedEvents(),
            $c->source['group_cap'],
            $muteResult->partial,
            $routed->notAnalysed,
            $patternFailures,
        ), $c->analysis['confidence_threshold']);
        try {
            $this->notifier->send($message, new Destination($c->notify['digest']['webhook']));
        } catch (DeliveryFailed $e) {
            $this->announceDigestFailure($e, $environment);

            throw $e;
        }

        $now = $this->clock->now()->format(DATE_ATOM);
        foreach ($findings as $finding) {
            foreach ($finding->fingerprints as $fp) {
                $buffer->put(Keys::reported($service, $fp), ['lastReportedAt' => $now, 'atCount' => $byFingerprint[$fp]->count ?? 0], $retention);
            }
        }
        $buffer->flush();
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

    /**
     * Each call is priced and added to Spend as it returns, so a later failure in the same run cannot lose what was already paid for.
     * A call that failed after billed attempts (AnalyzerFailed::$usage) is booked too; a paid call whose model has no price is booked at 0 with one warning.
     */
    private static function metered(Analyzer $inner, Spend $spend, CostCalculator $calculator, ?LoggerInterface $log): Analyzer
    {
        return new class ($inner, $spend, $calculator, $log) implements Analyzer {
            /** @var array<string, true> */
            private array $warned = [];

            public function __construct(private readonly Analyzer $inner, private readonly Spend $spend, private readonly CostCalculator $calculator, private readonly ?LoggerInterface $log)
            {
            }

            public function triage(TriageRequest $req): TriageResult
            {
                try {
                    $r = $this->inner->triage($req);
                } catch (AnalyzerFailed $e) {
                    throw $this->bookFailed($e);
                }
                $this->book($r->usage);

                return $r;
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                try {
                    $r = $this->inner->analyze($req);
                } catch (AnalyzerFailed $e) {
                    throw $this->bookFailed($e);
                }
                $this->book($r->usage);

                return $r;
            }

            private function bookFailed(AnalyzerFailed $e): AnalyzerFailed
            {
                if ($e->usage !== null) {
                    $this->book($e->usage);
                }

                return $e;
            }

            /** A paid call whose model has no price is booked at cost 0 (warned once per model); its result is never discarded. */
            private function book(Usage $u): void
            {
                try {
                    $this->spend->add($u, $this->calculator->cost($u));
                } catch (UnknownModelPrice) {
                    $this->spend->add($u, 0.0);
                    if (!isset($this->warned[$u->model])) {
                        $this->warned[$u->model] = true;
                        $this->log?->warning('paid analyzer call has no price configured; recorded at cost 0', ['model' => $u->model]);
                    }
                }
            }
        };
    }

    /** FR-040: the service context passes the same redaction scan as templates; unscannable means empty, never raw. */
    private function serviceContext(Redactor $redactor): string
    {
        if ($this->config->serviceContext === '') {
            return '';
        }
        $scanned = $redactor->redactText($this->config->serviceContext);
        if ($scanned === null) {
            $this->hostLog?->warning('service_context could not be scanned and was not sent', ['service' => $this->config->service]);
        }

        return $scanned ?? '';
    }

    /**
     * FR-039: reads each declared dependency's totals once, plans which need a line read, and reads them.
     * $templates null = nothing to correlate (baseline / quiet run): totals and snapshots only, no line reads.
     * A dependency that cannot be read never fails the run.
     *
     * @param list<string|null>|null $templates redacted templates of the candidates
     *
     * @return array{0: list<DependencyContext>, 1: list<string>, 2: int} contexts, unavailable names, count of undeclared call targets
     */
    private function readDependencies(Window $window, Normalizer $normalizer, Redactor $redactor, ?array $templates): array
    {
        $c = $this->config;
        $current = [];
        $previous = [];
        $declared = [];
        $failed = [];
        foreach ($c->dependencies as $name => $dep) {
            $name = (string) $name;
            $snap = $this->state->get(Keys::dependencySnapshot($c->service, $name, self::windowKey($window->start)));
            /** @var array<string, int>|null $prevTotals */
            $prevTotals = is_array($snap['totals'] ?? null) ? $snap['totals'] : null;
            $previous[$name] = $prevTotals;
            try {
                $tags = array_map('strval', $dep['stream_tags']);
                $current[$name] = $this->source->totals($window, new StreamSelector($tags));
                $declared[$name] = ['stream_tags' => $tags, 'source' => null];
            } catch (SourceUnavailable) {
                $failed[] = $name;
                $this->hostLog?->warning('dependency unavailable', ['dependency' => $name]);
            }
        }

        $plan = $templates === null
            ? new DependencyPlan()
            : (new DependencyResolver())->plan($templates, $current, $previous, $c->selection['growth_factor']);
        $contexts = [];
        if ($declared !== []) {
            $buffer = $this->pendingWrites ?? $this->state;
            try {
                $reader = new DependencyContextReader($normalizer, $redactor, $buffer, $c->state['retention'], $c->minGroupEventsForText);
                $contexts = $reader->read($c->service, $declared, fn (string $name): LogSource => $this->source, $plan, $window, $c->dependencyReadCap, $previous, $current)->contexts;
            } catch (Throwable $e) {
                $failed = [...$failed, ...array_keys($declared)];
                $this->hostLog?->warning('dependency read failed (' . $e::class . ')', ['service' => $c->service]);
            }
        }
        foreach ($contexts as $ctx) {
            if ($ctx->unavailable) {
                $failed[] = $ctx->name;
            }
        }

        return [array_values(array_filter($contexts, static fn (DependencyContext $d): bool => !$d->unavailable)), array_values(array_unique(array_map('strval', $failed))), $plan->undeclaredHosts];
    }

    /**
     * The dependency section counts toward the analysis input: when it does not fit, dependency top errors go first
     * (lowest count), never the candidates.
     *
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $deps
     *
     * @return list<DependencyContext>
     */
    private static function fitDependencies(array $groups, array $deps, int $maxInputTokens): array
    {
        while ($deps !== [] && Budget::estimateTokens(CompactPayload::encode($groups, $deps)) > $maxInputTokens) {
            $at = null;
            foreach ($deps as $i => $d) {
                foreach ($d->topErrors as $j => $g) {
                    if ($at === null || $g->count <= $deps[$at[0]]->topErrors[$at[1]]->count) {
                        $at = [$i, $j];
                    }
                }
            }
            if ($at === null) {
                break;
            }
            $top = $deps[$at[0]]->topErrors;
            unset($top[$at[1]]);
            $deps[$at[0]] = $deps[$at[0]]->withTopErrors(array_values($top));
        }

        return $deps;
    }

    private function routerSettings(string $serviceContext): RouterSettings
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
            $serviceContext,
        );
    }

    private static function plainNotice(string $text): Message
    {
        return new Message($text, [['kind' => 'notice', 'text' => $text]]);
    }

    /** Sends once per key (the key lives two days); a failed send gives the key back so the next run retries. */
    private function noticeOnce(string $key, Message $message, Destination $to): void
    {
        $owner = bin2hex(random_bytes(8));
        if (!$this->state->acquire($key, $owner, Keys::ttl(Keys::SPEND, 0, 0))) {
            return;
        }
        try {
            $this->notifier->send($message, $to);
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
        $this->pendingWrites?->flush();
        $this->state->put(
            Keys::snapshot($this->config->service, self::windowKey($window->end)),
            ['groups' => $counts, 'totals' => $totals, 'deploy' => $this->deployInfo?->toSnapshot()],
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
