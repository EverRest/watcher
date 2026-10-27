<?php

declare(strict_types=1);

namespace Watcher;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Watcher\Baseline\BaselineResolver;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Budget;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\ModelRouter;
use Watcher\Analyze\PayloadExtras;
use Watcher\Analyze\RouterSettings;
use Watcher\Analyze\TriageCheckpoint;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Fleet\FleetSeam;
use Watcher\Issues\IssueSource;
use Watcher\Issues\SentryMerge;
use Watcher\Metrics\MetricAnomalies;
use Watcher\Metrics\MetricSource;
use Watcher\Metrics\MetricsReader;
use Watcher\Notify\DigestExtras;
use Watcher\Incident\Delivery;
use Watcher\Incident\KnownIssues;
use Watcher\Incident\KnownLineRenderer;
use Watcher\Incident\KnownReferences;
use Watcher\Analyze\VerdictCache;
use Watcher\ApiContext\ApiContextReader;
use Watcher\ApiContext\EndpointContext;
use Watcher\ApiContext\EndpointResolver;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Context\CodeLinkBuilder;
use Watcher\Context\DependencyContext;
use Watcher\Context\DependencyContextReader;
use Watcher\Context\DependencyPlan;
use Watcher\Context\DependencyResolver;
use Watcher\Context\DeployInfo;
use Watcher\Context\LogLinkBuilder;
use Watcher\Context\TraceIdCollector;
use Watcher\Context\TraceLinkBuilder;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\DeliveryProgress;
use Watcher\Notify\Destination;
use Watcher\Notify\DigestData;
use Watcher\Notify\DigestRenderer;
use Watcher\Notify\Message;
use Watcher\Notify\NoticeRenderer;
use Watcher\Notify\SlackText;
use Watcher\Notify\Notifier;
use Watcher\Observe\ObserveCounters;
use Watcher\Observe\ObservingNotifier;
use Watcher\Observe\RunPublisher;
use Watcher\Observe\SinkDispatcher;
use Watcher\Operate\CapturingNotifier;
use Watcher\Operate\DryRunStateStore;
use Watcher\Operate\EstimatingAnalyzer;
use Watcher\Operate\RunOptions;
use Watcher\Operate\RunStats;
use Watcher\Operate\RunSummaryData;
use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\EventCollector;
use Watcher\Reduce\Fingerprinter;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;
use Watcher\Schedule\FatalGuard;
use Watcher\Schedule\LockLost;
use Watcher\Schedule\RunGuard;
use Watcher\Schedule\SystemClock;
use Watcher\Schedule\Window;
use Watcher\Select\LevelAnomaly;
use Watcher\Select\MuteList;
use Watcher\Select\WatchRules;
use Watcher\Select\Candidate;
use Watcher\Select\SelectionResult;
use Watcher\Select\Selector;
use Watcher\Select\Tier;
use Watcher\Source\LogSource;
use Watcher\Source\ReadDiagnostics;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\State\BufferedStateStore;
use Watcher\State\Keys;
use Watcher\State\Purgeable;
use Watcher\State\RenewableLock;
use Watcher\State\StateStore;

/** Single entry point (FR-033). */
final class Watcher
{
    /** A window whose analysis failed this many times is recorded as failed and not tried again (a refusal counts as the last one at once). */
    private const MAX_ANALYSIS_FAILURES = 2;

    /** Failure notices sent per window: hourly triggers of a persistent failure must not post one each (A4-07). */
    private const MAX_FAIL_NOTICES = 3;

    /** The delivered-digest marker only has to outlive the due hour's triggers. */
    private const DELIVERED_TTL = 21600;

    /** Consecutive windows with no event at all from the source before it is reported (A4-08). */
    private const ZERO_WINDOWS = 3;

    private readonly ClockInterface $clock;

    /** Window key of the snapshot that dependency totals are compared with. */
    private string $previousKey = '';

    private readonly StateStore $state;

    private readonly Analyzer $analyzer;

    private readonly Notifier $notifier;

    /** The unwrapped store: a dry run reads the real "already done" record through it. */
    private readonly StateStore $realState;

    private readonly RunOptions $options;

    /** @var callable(string): void */
    private $out;

    private ?EstimatingAnalyzer $estimator = null;

    private readonly RunLedger $ledger;

    private ?ObservingNotifier $observer = null;

    // Availability bookkeeping (A4-01, A4-05), reset by run().
    private ?RunGuard $guard = null;

    private ?int $fleetDeadline = null;

    /** Set only by switchedOff(): the master switch was off, so no collaborator exists. */
    private ?RunResult $disabled = null;

    private ?string $lockKey = null;

    private ?string $lockOwner = null;

    private int $lockRenewedAt = 0;

    private ?string $blindCode = null;

    private ?WindowKeys $keys = null;

    /** @var array{gapFrom: ?DateTimeImmutable, gapTo: ?DateTimeImmutable, gapWindows: ?int, catchUpBatch: ?int}|null */
    private ?array $scheduleMeta = null;

    /**
     * @param callable(string): void|null $out dry-run output (default: stdout); ignored for a real run
     */
    public function __construct(
        private readonly WatchConfig $config,
        private readonly LogSource $source,
        StateStore $state,
        Analyzer $analyzer,
        Notifier $notifier,
        ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $hostLog = null,
        ?RunOptions $options = null,
        mixed $out = null,
        private readonly ?FleetSeam $fleet = null,
        ?SinkDispatcher $sinks = null,
        ?ObserveCounters $counters = null,
        private readonly ?MetricSource $metrics = null,
        private readonly ?IssueSource $issues = null,
        /** @var (\Closure(StateStore, Redactor): ?ApiContextReader)|null spec 010: built per run with the run's state (a dry run's wrapper) and redactor */
        private readonly ?\Closure $apiContext = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->options = $options ?? RunOptions::default();
        $this->realState = $state;
        $this->out = is_callable($out) ? $out : static function (string $s): void {
            fwrite(STDOUT, $s);
        };
        if ($this->options->dryRun) {
            // Reads pass through; every write, lock and counter is swallowed; nothing is posted (spec 002, US3).
            $state = new DryRunStateStore($state);
            $notifier = $this->options->estimate ? self::discarding() : new CapturingNotifier($this->out);
            $notifier = $fleet?->notifierFor($config->stateKey, $notifier) ?? $notifier; // a combined fleet digest is held in a dry run too
            if ($this->options->estimate) {
                $analyzer = $this->estimator = new EstimatingAnalyzer();
            }
        }
        $this->state = $state;
        $this->analyzer = $analyzer;
        if ($sinks !== null && !$this->options->dryRun) {
            // Spec 013: a failed chat delivery becomes a delivery_failure event (code only), then travels on untouched.
            $notifier = $this->observer = new ObservingNotifier($notifier);
        }
        $this->notifier = $notifier;
        $publisher = $sinks === null ? null : new RunPublisher($sinks, $config->observe, $this->clock, $this->options->dryRun ? null : $counters, $hostLog);
        $heartbeat = $this->options->dryRun || !$config->on('heartbeat') ? null : Factory::heartbeat($config, $state, $this->clock, $hostLog);
        $this->ledger = new RunLedger(config: $config, state: $state, clock: $this->clock, heartbeat: $heartbeat, notifier: $notifier, hostLog: $hostLog, publisher: $publisher, topGroups: $config->observe->topGroups);
        $this->observer?->observeWith(fn () => $this->ledger->observation());
    }

    private static function discarding(): Notifier
    {
        return new class () implements Notifier {
            public function send(Message $message, Destination $to): void
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $config
     * @throws Config\InvalidConfig before any external call; callers map it to RunResult::invalidConfig()
     */
    public static function fromConfig(#[\SensitiveParameter] array $config, ?LoggerInterface $hostLog = null, ?ClockInterface $clock = null, ?RunOptions $options = null, mixed $out = null): self
    {
        if (WatchConfig::masterSwitchOff($config)) {
            // Before validation: the kill switch works on a broken or half-filled config, and builds nothing.
            return self::switchedOff($config, $hostLog);
        }
        $c = WatchConfig::fromArray(WatchConfig::withoutFleet($config));
        $clock ??= new SystemClock();
        $http = Factory::http();
        $state = Factory::state($c, $clock, $hostLog); // the one state instance: the unreadable-counter wrapper, the run and the sink counters share it
        $counters = new ObserveCounters($state, $c->stateKey, $c->state['retention']);
        // Spec 009: the sources end their calls once the run must wind down; the guard exists only inside run(), so they ask the Watcher.
        $late = new class () {
            public ?Watcher $watcher = null;
        };
        $stop = static fn (): ?string => $late->watcher?->stopReason();

        $late->watcher = new self(
            config: $c,
            source: Factory::source($c, $http, $clock),
            state: $state,
            analyzer: Factory::analyzer($c),
            notifier: Factory::notifier($http, $c->schedule['lock_ttl']),
            clock: $clock,
            hostLog: $hostLog,
            options: $options,
            out: $out,
            sinks: $c->enabled ? Factory::observe($c, $clock, $hostLog, $counters, stdoutToStderr: $options?->json === true) : null,
            counters: $counters,
            metrics: $c->enabled ? Factory::metricSource($c, $http, $stop) : null,
            issues: $c->enabled ? Factory::issueSource($c, $http, $clock, $stop) : null,
            apiContext: Factory::apiContext($c, $http, $clock),
        );

        return $late->watcher;
    }

    /** Why this run must wind down (deadline or memory), or null; the metrics and Sentry sources poll it between requests. */
    public function stopReason(): ?string
    {
        return $this->guard?->reason();
    }

    /** @param array<string, mixed> $config only `service` and `environment` are read, and only when they look like names */
    private static function switchedOff(#[\SensitiveParameter] array $config, ?LoggerInterface $hostLog): self
    {
        $name = static fn (mixed $v): ?string => is_string($v) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._@-]{0,63}$/D', $v) === 1 ? $v : null;
        $self = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(self::class, 'hostLog'))->setValue($self, $hostLog); // the one readonly a caller can observe
        $self->disabled = new RunResult(RunOutcome::Disabled, null, new RunSummaryData(RunOutcome::Disabled, $name($config['service'] ?? null), null, environment: $name($config['environment'] ?? null)));

        return $self;
    }

    /** State-key component for a window end (UTC, second precision). */
    public static function windowKey(DateTimeImmutable $end): string
    {
        return $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** A fleet gives each service a share of its time budget: the run deadline is the smaller of that and schedule.run_deadline. */
    public function limitRunTo(int $seconds): void
    {
        $this->fleetDeadline = max(1, $seconds);
    }

    public function run(?DateTimeImmutable $now = null): RunResult
    {
        if ($this->disabled !== null) {
            return $this->disabled;
        }
        if (!$this->config->enabled) {
            // The master switch: no source, state, model or notifier access, not even a heartbeat.
            return new RunResult(RunOutcome::Disabled, null, new RunSummaryData(RunOutcome::Disabled, $this->config->service, null, environment: $this->config->environment));
        }
        $this->ledger->reset();
        $this->guard = new RunGuard($this->clock, min($this->config->schedule['run_deadline'], $this->fleetDeadline ?? PHP_INT_MAX));
        $this->blindCode = null;
        $this->lockKey = $this->lockOwner = null;
        $this->keys = null;

        return $this->conclude($this->attempt($now));
    }

    private function attempt(?DateTimeImmutable $now): RunResult
    {
        $c = $this->config;
        $now ??= $this->clock->now();
        // Before any external call and before the lock: a trigger outside the run_at hour costs nothing (FR-002).
        // A dry run previews the window the real run would analyse, whatever the hour.
        $gate = Factory::gate($c);
        if ($this->options->dryRun) {
            $this->scheduleMeta = null;

            return $this->runLockedAttempt($gate->latest($now), $now);
        }

        $pending = $gate->pending($now);
        if ($pending === []) {
            return new RunResult(RunOutcome::SkippedNotDue);
        }
        if (($c->schedule['every'] ?? null) !== null) {
            $pending = $gate->pending($now, $this->highWaterEnd());
        }
        $todo = [];
        foreach ($pending as $candidate) {
            $probe = new WindowKeys($c->stateKey, $candidate, $c->state['retention'], $c->schedule['lock_ttl']);
            try {
                if ($this->state->get($probe->run()) !== null || $this->state->get($probe->delivered()) !== null) {
                    continue;
                }
            } catch (Throwable) {
            }
            $todo[] = $candidate;
        }
        if ($todo === []) {
            $this->ledger->started($pending[0]);

            return new RunResult(RunOutcome::SkippedDone);
        }
        usort($todo, static fn (Window $a, Window $b): int => $a->end <=> $b->end);

        $last = null;
        $batch = count($todo);
        foreach ($todo as $index => $window) {
            $this->scheduleMeta = $this->buildScheduleMeta($window, $batch, $index === 0);
            $last = $this->runLockedAttempt($window, $now);
            if ($index + 1 < $batch && $this->continueCatchUp($last)) {
                $this->betweenCatchUpWindows($last);
                continue;
            }
            break;
        }
        $this->scheduleMeta = null;

        return $last;
    }

    private function runLockedAttempt(Window $window, DateTimeImmutable $now): RunResult
    {
        $c = $this->config;
        $this->ledger->started($window);
        $this->purge();
        $owner = bin2hex(random_bytes(8));
        $keys = new WindowKeys($c->stateKey, $window, $c->state['retention'], $c->schedule['lock_ttl']);
        $this->keys = $keys;
        $lockKey = $keys->lock();
        $fatal = null;

        try {
            if ($this->options->dryRun) {
                $this->announceDryRun($keys);
            }
            if (!$this->state->acquire($lockKey, $owner, $keys->lockTtl())) {
                return new RunResult(RunOutcome::SkippedLocked);
            }
            $this->lockKey = $lockKey;
            $this->lockOwner = $owner;
            $this->lockRenewedAt = $this->clock->now()->getTimestamp();
            $fatal = new FatalGuard(fn () => $this->state->release($lockKey, $owner), $this->hostLog, $c->service);
            $fatal->arm();

            return $this->locked($window, $keys);
        } catch (SourceUnavailable $e) {
            return $this->failWithNotice('source', $e->getMessage(), 'source_unavailable');
        } catch (Config\InvalidConfig $e) {
            return $this->failWithNotice('config', $e->getMessage(), 'invalid_config');
        } catch (AnalyzerFailed $e) {
            return $this->failWithNotice('analysis', $e->getMessage(), $e->refused ? 'analysis_refused' : 'analysis_failed');
        } catch (LockLost $e) {
            return $this->fail($e->getMessage(), 'lock_lost');
        } catch (DeliveryFailed $e) {
            return $this->fail($e->getMessage(), 'delivery_failed');
        } catch (Throwable $e) {
            return $this->failWithNotice('run', 'internal error (' . $e::class . ')', 'internal_error');
        } finally {
            $fatal?->disarm();
            try {
                $this->state->release($lockKey, $owner);
            } catch (Throwable) {
            }
        }
    }

    private function continueCatchUp(RunResult $result): bool
    {
        return in_array($result->outcome, [RunOutcome::Reported, RunOutcome::Quiet, RunOutcome::Baselined], true);
    }

    private function betweenCatchUpWindows(RunResult $result): void
    {
        $this->ledger->finish($result->outcome, $this->notices(), $this->environment());
        $this->ledger->reset();
        $this->guard = new RunGuard($this->clock, min($this->config->schedule['run_deadline'], $this->fleetDeadline ?? PHP_INT_MAX));
    }

    /** @return array{gapFrom: ?DateTimeImmutable, gapTo: ?DateTimeImmutable, gapWindows: ?int, catchUpBatch: ?int} */
    private function buildScheduleMeta(Window $window, int $batch, bool $first): array
    {
        $gap = $this->gapSpan($window);

        return [
            'gapFrom' => $gap['from'] ?? null,
            'gapTo' => $gap['to'] ?? null,
            'gapWindows' => ($this->config->schedule['every'] ?? null) === null ? null : ($gap['n'] ?? null),
            'catchUpBatch' => $batch > 1 && $first ? $batch : null,
        ];
    }

    private function highWaterEnd(): ?DateTimeImmutable
    {
        if ($this->options->dryRun) {
            return null;
        }
        try {
            $stored = $this->state->get(Keys::highWater($this->config->stateKey));
        } catch (Throwable) {
            return null;
        }
        if (!is_array($stored) || !is_string($stored['end'] ?? null)) {
            return null;
        }
        try {
            return new DateTimeImmutable($stored['end']);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array{from: DateTimeImmutable, to: DateTimeImmutable, n: int}|null */
    private function gapSpan(Window $window): ?array
    {
        if ($this->options->dryRun) {
            return null;
        }
        try {
            $stored = $this->state->get(Keys::highWater($this->config->stateKey));
        } catch (Throwable) {
            return null;
        }
        if (!is_array($stored) || !is_string($stored['end'] ?? null)) {
            return null;
        }
        try {
            $hw = new DateTimeImmutable($stored['end']);
        } catch (\Exception) {
            return null;
        }
        if ($hw >= $window->start) {
            return null;
        }
        $period = max(1, (int) ($this->config->schedule['period_seconds'] ?? 3600));

        return [
            'from' => $hw,
            'to' => $window->start,
            'n' => max(1, (int) round(($window->start->getTimestamp() - $hw->getTimestamp()) / $period)),
        ];
    }

    /**
     * Extends the run lock when less than half of its lifetime is left (A4-05), between pipeline steps and before each model call.
     * A store without renew keeps the old behaviour (the deadline stays below lock_ttl anyway).
     */
    private function renewLock(): void
    {
        if (!$this->state instanceof RenewableLock || $this->lockKey === null || $this->lockOwner === null) {
            return;
        }
        $ttl = $this->config->schedule['lock_ttl'];
        $now = $this->clock->now()->getTimestamp();
        if ($now - $this->lockRenewedAt < intdiv($ttl, 2)) {
            return;
        }
        try {
            if ($this->state->renew($this->lockKey, $this->lockOwner, $ttl)) {
                $this->lockRenewedAt = $now;
            } else {
                throw new LockLost('run lock was lost before it could be renewed'); // another runner may own the window now
            }
        } catch (LockLost $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->hostLog?->warning('run lock renewal failed: ' . $e::class, ['service' => $this->config->service]);
        }
    }

    /** Only a store that opts in (the file store) is purged; once the trigger is due, before the lock. A fault never changes the run. */
    private function purge(): void
    {
        if ($this->options->dryRun || !$this->config->on('purge') || !$this->state instanceof Purgeable) {
            return;
        }
        try {
            $this->state->purge();
        } catch (Throwable $e) {
            $this->hostLog?->warning('state purge failed: ' . $e::class, ['service' => $this->config->service]);
        }
    }

    private function announceDryRun(WindowKeys $keys): void
    {
        $this->emit($this->options->estimate
            ? "== DRY RUN --estimate: no model calls made ==\n"
            : "== DRY RUN: nothing posted, no state written. Model calls were real. ==\n");
        // Spec 009: the optional sources are read-only but real and credentialed; a dry run and an estimate make the same reads as a run.
        $reads = array_filter([$this->metrics !== null ? 'metrics' : null, $this->issues !== null ? 'sentry' : null]);
        if ($reads !== []) {
            $this->emit('== external reads made (read-only, credentialed): ' . implode(', ', $reads) . " ==\n");
        }
        if ($this->realState->get($keys->run()) !== null) {
            $this->emit("== window already reported by a real run: executed anyway ==\n");
        }
    }

    private function emit(string $text): void
    {
        ($this->out)($text);
    }

    /**
     * Last step of every run: the summary data (all outcomes), the liveness record and the weekly line.
     * Nothing here can change the outcome (FR-005); a dry run records nothing.
     */
    private function conclude(RunResult $r): RunResult
    {
        $outcome = $r->outcome;
        $this->ledger->finish($outcome, $this->notices(), $this->environment());
        if ($this->estimator !== null) {
            $this->printEstimate($this->estimator);
        }

        // Spec 013: the events leave once, after the heartbeat is written; bounded by what is left of the run deadline.
        $summary = $this->ledger->publish($this->ledger->summary($outcome, $this->options->dryRun), $this->guard?->remainingSeconds(), $this->options->dryRun, $this->emit(...));

        return new RunResult($outcome, $r->failureReason, $summary);
    }

    private function printEstimate(EstimatingAnalyzer $e): void
    {
        $groups = [];
        foreach ($e->steps() as $step) {
            $groups[$step['step']] = ($groups[$step['step']] ?? 0) + $step['groups'];
        }
        $text = '';
        foreach (['triage', 'analyze'] as $name) {
            $text .= sprintf("%-9s %7s\n", $name, (string) ($groups[$name] ?? '-'));
        }
        $text .= sprintf("%-9s %7s   (up to %d groups, not estimated)\n", 'escalate', '-', $this->config->analysis['escalation_cap']);
        $this->emit($text);
    }

    private function fail(string $reason, string $code): RunResult
    {
        $this->ledger->setCode($code);
        foreach ($this->config->secrets() as $secret) {
            if (strlen((string) $secret) >= 8) {
                $reason = str_replace((string) $secret, '[redacted]', $reason); // a provider error can echo a credential
            }
        }
        $this->hostLog?->error('watcher run failed: ' . $reason, ['service' => $this->config->service]);

        return new RunResult(RunOutcome::Failed, $reason);
    }

    /** A failed notice must never mask the original failure or crash the run (FR-031). */
    private function failWithNotice(string $kind, string $reason, string $code): RunResult
    {
        $result = $this->fail($reason, $code);
        if (!$this->config->on('notice')) {
            return $result; // the host log and exit code carry it
        }
        if ($this->keys !== null) {
            try {
                $n = $this->state->increment($this->keys->failNotices(), 1.0, Keys::ttl(Keys::FAILNOTICE, 0, 0));
                if ($n > self::MAX_FAIL_NOTICES) {
                    return $result; // already told three times for this window
                }
            } catch (Throwable) {
                // the counter is best effort; send
            }
        }
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
        if (!$this->config->on('notice') || $this->config->destination('notice')->sameAs($this->config->destination('digest'))) {
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

        return new NoticeRenderer($c->secrets());
    }

    private function noticeDestination(): Destination
    {
        return $this->config->destination('notice');
    }

    private function environment(): string
    {
        $env = $this->config->environment ?? $this->config->source['stream_tags']['env'] ?? null;

        return is_string($env) ? $env : 'default';
    }


    private function effectiveWindow(Window $window): Window
    {
        if ($this->options->dryRun) {
            return $window;
        }
        $stored = $this->state->get(Keys::highWater($this->config->stateKey));
        if (!is_array($stored) || !is_string($stored['end'] ?? null)) {
            return $window;
        }
        try {
            $hw = new DateTimeImmutable($stored['end']);
        } catch (\Exception) {
            return $window;
        }
        if ($hw <= $window->start || $hw >= $window->end) {
            return $window;
        }

        return new Window($hw, $window->end);
    }

    private function advanceHighWater(Window $window, WindowKeys $keys): void
    {
        if ($this->options->dryRun) {
            return;
        }
        $hwKey = Keys::highWater($this->config->stateKey);
        $ttl = Keys::ttl(Keys::HIGH_WATER, $this->config->state['retention'], $this->config->schedule['lock_ttl']);
        $stored = $this->state->get($hwKey);
        if (is_array($stored) && is_string($stored['end'] ?? null)) {
            try {
                if (new DateTimeImmutable($stored['end']) >= $window->end) {
                    return;
                }
            } catch (\Exception) {
                // overwrite corrupt value
            }
        }
        $this->state->put($hwKey, [
            'v' => 1,
            'end' => $window->end->format(DATE_ATOM),
            'run' => $keys->end(),
        ], $ttl);
    }

    private function locked(Window $window, WindowKeys $keys): RunResult
    {
        $window = $this->effectiveWindow($window);
        $c = $this->config;
        $service = $c->service;
        $key = $c->stateKey; // prefix of every state key: service, or service@environment
        $retention = $c->state['retention'];
        $endKey = $keys->end();
        $runKey = $keys->run();
        $started = $this->clock->now();

        if ($this->state->get($runKey) !== null || (!$this->options->dryRun && $this->state->get($keys->delivered()) !== null)) {
            return new RunResult(RunOutcome::SkippedDone);
        }

        // Built before any external call so a bad configuration fails at start, not mid-run.
        $declaredDependencies = $c->on('dependencies') ? array_map('strval', array_keys($c->dependencies)) : [];
        $normalizer = new Normalizer($declaredDependencies, $c->dependencyDomains, extraPatterns: $c->redactionPatterns);
        $pathMap = is_array($c->repo['path_map'] ?? null) ? array_map('strval', $c->repo['path_map']) : [];
        $redactor = new Redactor($normalizer, $c->redactionPatterns, $pathMap);

        $selector = new StreamSelector($c->source['stream_tags']);
        $readMin = $c->source['read_min_level'];
        $totals = $c->on('level_anomaly') ? $this->source->totals($window, $selector) : [];

        /** @var array{groups?: array<string, int>, totals?: array<string, int>, deploy?: array<string, string|null>}|null $prev */
        $prev = $this->state->get($keys->previousSnapshot());
        $comparedWith = null;
        $this->previousKey = self::windowKey($window->start);
        if ($prev === null) {
            // H6: the previous window has no snapshot (skipped or failed day): fall back to the last good one, if recent enough.
            [$prev, $comparedWith, $this->previousKey] = $this->lastGoodSnapshot($window) + [null, null, $this->previousKey];
        }
        if ($comparedWith !== null) {
            $this->ledger->observation()->warn('comparison_gap', null, max(1, (int) round(($window->start->getTimestamp() - $comparedWith->getTimestamp()) / 86400)));
        }
        $fpvResetFrom = null;
        if ($prev !== null && !Fingerprinter::snapshotUsable($prev)) {
            $fpvResetFrom = Fingerprinter::snapshotVersion($prev);
            $prev = null;
            $comparedWith = null;
            $this->previousKey = self::windowKey($window->start);
        }
        if ($prev !== null && !BaselineResolver::intact($prev)) {
            // A corrupt record is missing, as on a first run; the summary counts it. Nothing below reads its values.
            $this->ledger->stats()->stateUnreadable++;
            $prev = null;
            $comparedWith = null;
            $this->previousKey = self::windowKey($window->start);
        }
        $this->ledger->defer();
        $deploy = $c->on('deploy') ? DeployInfo::resolve($c->deploy, $prev['deploy'] ?? null, $this->source, $window, $selector) : new DeployInfo(null, null, null, false);
        $this->ledger->setDeployInfo($deploy);
        // A snapshot without `totals` (level_anomaly was off) is unknown, not empty: no level "appeared".
        $anomalies = $c->on('level_anomaly')
            ? LevelAnomaly::levels($prev === null || !array_key_exists('totals', $prev) ? null : $prev['totals'], $totals, $c->selection['growth_factor'], $readMin)
            : [];

        $rules = new WatchRules($c->watchRules);
        $mute = new MuteList($c->mute);
        // Spec 009 US4: a tracing link needs the earliest valid trace id per group, kept in memory only (never on a group).
        $traceField = $c->links['tempo']['trace_field'] ?? $c->links['datadog_apm']['trace_field'] ?? (isset($c->links['tempo']) || isset($c->links['datadog_apm']) ? 'trace_id' : null);
        $traces = $traceField === null ? null : new TraceIdCollector((string) $traceField);
        $collector = new EventCollector($c, $this->source, $normalizer, $rules, $this->guard, $traces === null ? null : $traces->observe(...));
        $groups = $collector->collect($window, $selector, $anomalies);
        $extras = ['metricsLine' => null, 'unavailable' => [], 'sentryDropped' => 0, 'anomaliesNotShown' => 0];
        if ($this->issues !== null && $c->sentry !== null && $this->guard?->reason() === null) {
            $before = count($groups);
            [$groups, $failure, $extras['sentryDropped']] = (new SentryMerge($this->issues, $c->sentry->maxIssues, $c->minGroupEventsForText))->merge($window, $groups, $normalizer);
            $this->ledger->stats()->setSentry(['read' => count($groups) - $before + $extras['sentryDropped'], 'dropped' => $extras['sentryDropped'], 'merged' => count($groups) - $before, 'failed' => $failure === null ? 0 : 1]);
            if ($failure !== null) {
                $extras['unavailable']['sentry'] = $failure;
                $this->hostLog?->warning('sentry unavailable: ' . $failure, ['service' => $service]);
            }
        }
        $this->ledger->stats()->eventsRead = $collector->eventsRead();
        $this->ledger->stats()->unscannableEvents = $collector->unscannableEvents();
        $this->ledger->stats()->oversizedLines = $collector->oversizedLines();
        $this->ledger->stats()->overflowedEvents = $collector->overflowedEvents();
        $this->ledger->stats()->readQuality = $this->source instanceof ReadDiagnostics ? $this->source->readDiagnostics() : [];
        // Blindness (A4-03, A4-08): what this run could not see, as counts. Sent as a short notice even when the run is otherwise quiet.
        $readReason = $this->guard?->reason() ?? $collector->truncationReason() ?? ($collector->truncated() ? 'cap' : null);
        $zeroStreak = $this->zeroStreak($key, $collector->mainEventsRead(), $totals);
        $blind = fn (bool $quiet, bool $digestSent = false): ?string => $this->blindLine($quiet, $digestSent, $readReason, $collector->eventsRead(), $collector->overflowedEvents(), $zeroStreak);
        $this->ledger->stats()->groups = count($groups);
        // Muted groups leave first, so they reach neither a model nor the selector; the snapshot keeps them for growth.
        $allGroups = $groups;
        $muteResult = $mute->filter($groups, $collector->muteHits(), $started);
        $groups = $muteResult->kept;
        $factor = $c->selection['growth_factor'];
        $knownResult = null;
        $knownLine = null;
        $knownGrown = [];
        $knownBuffer = null;
        if ($c->on('known_issues') && $c->incident->knownIssues !== []) {
            $knownBuffer = new BufferedStateStore($this->state);
            $knownResult = (new KnownIssues($c->incident->knownIssues, $factor))->partition(
                $groups,
                $started,
                new KnownReferences($knownBuffer, $key, $retention),
            );
            $groups = $knownResult->kept;
            $knownGrown = $knownResult->grown;
            $knownLine = KnownLineRenderer::format($knownResult->lines);
        }
        // Spec 004: what each group is compared with is decided by baseline.mode; `$prev` stays for deploy info and level anomalies.
        $comparison = (new BaselineResolver(
            $this->state,
            Factory::gate($c),
            $key,
            $c->on('baseline') ? $c->baseline : ['mode' => 'previous'] + $c->baseline,
            new \DateTimeZone($c->schedule['timezone']),
            $factor,
        ))->resolve($window, array_map(static fn (ProblemGroup $g): string => $g->fingerprint, $allGroups), $prev, $comparedWith ?? $window->start);
        $this->ledger->stats()->stateUnreadable += $comparison->unreadable; // corrupt baseline snapshots, treated as missing
        $groups = array_map(
            static fn (ProblemGroup $g): ProblemGroup => $g->withComparison($comparison->previousFor($g->fingerprint), $comparison->statusFor($g->fingerprint, $g->count)),
            $groups,
        );

        $groups = $rules->apply($groups, $collector->ruleHits());
        $this->ledger->observeRead($groups, $collector, $muteResult->mutedCount);
        // Counts only: a regex that failed to evaluate (backtrack limit) fails open, and the operator should hear about it.
        $patternFailures = $rules->failures() + $mute->failures() + $collector->patternFailures();
        $this->ledger->stats()->patternFailures = $patternFailures;
        if ($patternFailures > 0) {
            $this->hostLog?->warning('pattern evaluation failures: rules may have been skipped', ['service' => $service, 'count' => $patternFailures]);
        }
        $environment = $this->environment();
        $renderer = new DigestRenderer();

        if ($comparison->isBaseline()) {
            $this->readDependencies($window, $normalizer, $redactor, null);
            $this->ledger->snapshot($window, $allGroups, $totals);
            if ($c->on('notice')) {
                $eventCount = array_sum(array_map(static fn (ProblemGroup $g): int => $g->count, $groups));
                $notice = $fpvResetFrom === null
                    ? $renderer->renderBaseline($service, $environment, count($groups), $eventCount)
                    : $renderer->renderFingerprintVersionReset($service, $environment, count($groups), $eventCount, $fpvResetFrom, Fingerprinter::FPV);
                $this->notifier->send($notice, $c->destination('notice'));
            }
            $this->blindNotice($blind(false), $key, $endKey); // a baseline run only records: cap and overflow are not news yet
            $this->ledger->runRecord($runKey, RunOutcome::Baselined, $started, $this->blindCode);
            $this->advanceHighWater($window, $keys);

            return new RunResult(RunOutcome::Baselined);
        }

        $selection = Tier::select($groups, $c->selection['min_level'], $c->selection['top_n'], $anomalies, $knownGrown); // log groups as before; Sentry groups never displace a log Error
        $pool = $selection->candidates;
        $payloadExtras = null;
        if ($this->metrics !== null && $c->metrics !== null && $this->guard?->reason() === null) {
            // Spec 009: numbers next to the log findings, and a metric move no Error-or-above candidate explains becomes a candidate of its own.
            $context = (new MetricsReader($this->metrics, $c->metrics->definitions, $c->metrics->maxPoints, $normalizer, $redactor))->read($window);
            $found = (new MetricAnomalies($service, $c->metrics->definitions, $c->metrics->maxAnomalies, $factor))->detect($context, $pool, $window, $prev['groups'] ?? []);
            $extras['metricsLine'] = $context->unavailable || $context->line() === '' ? null : $context->line();
            if ($context->unavailable) {
                $extras['unavailable']['metrics'] = $context->failure ?? 'unreachable';
                $this->hostLog?->warning('metrics unavailable: ' . ($context->failure ?? 'unreachable'), ['service' => $service]);
            }
            $extras['anomaliesNotShown'] = $found->truncated;
            $this->ledger->stats()->setMetrics(['read' => count($context->readings), 'failed' => $context->unavailable ? 1 : 0, 'anomalies' => count($found->candidates)]);
            $payloadExtras = $context->unavailable ? null : PayloadExtras::fromContext($context, $found->metricByFingerprint);
            $pool = Tier::merge($pool, $found->candidates);
            $allGroups = [...$allGroups, ...array_map(static fn (Candidate $a): ProblemGroup => $a->group, $found->candidates)];
        }
        $digestExtras = new DigestExtras(...$extras);
        $candidates = [];
        foreach ($pool as $cand) {
            $reported = $this->state->get($keys->reported($cand->group->fingerprint));
            if ($reported === null || $cand->group->status === GroupStatus::Grown) {
                $candidates[] = $cand;
            }
        }

        $this->ledger->stats()->candidates = count($candidates);
        $expired = $this->guard?->reason() !== null; // deadline or memory: no model call may start now (A4-01)
        if ($candidates === [] || !$c->on('digest') || $expired) {
            // Digest off: nothing could be posted, so no model is paid for; the window is still read and snapshotted.
            $line = $blind(true);
            $this->ledger->setCode($candidates === [] || $expired ? $this->ledger->code() : 'digest_disabled');
            if (!$expired) {
                $this->readDependencies($window, $normalizer, $redactor, null);
            }
            $this->ledger->snapshot($window, $allGroups, $totals);
            if ($expired && $this->retryCutWindow($keys, $retention, count($candidates), $line)) {
                return new RunResult(RunOutcome::Quiet); // no done record: the next trigger reads this window again (R-3)
            }
            $this->blindNotice($line, $key, $endKey);
            $this->ledger->runRecord($runKey, RunOutcome::Quiet, $started, $this->blindCode);
            $this->advanceHighWater($window, $keys);

            return new RunResult(RunOutcome::Quiet);
        }

        $read = new WindowRead(window: $window, allGroups: $allGroups, totals: $totals, anomalies: $anomalies, previousTotals: $prev['totals'] ?? [], comparedWith: $comparedWith, deploy: $deploy, collector: $collector, muteResult: $muteResult, comparison: $comparison, patternFailures: $patternFailures, extras: $digestExtras->isEmpty() ? null : $digestExtras, payloadExtras: $payloadExtras, traces: $traces, knownResult: $knownResult, knownLine: $knownLine);
        $analysis = $this->analyze(read: $read, candidates: $candidates, normalizer: $normalizer, redactor: $redactor, keys: $keys, started: $started, buffer: $knownBuffer);

        $routed = $analysis->routed;
        $findings = $routed->findings; // already scrubbed by the decorator, before the router cached them
        $dropped = $analysis->truncation->droppedCount + $routed->droppedByTopM;

        if ($findings === [] && $dropped === 0 && $routed->notAnalysed === 0 && $routed->escalationsSkippedSize === 0 && $patternFailures === 0 && $routed->unreported === 0 && $routed->triageCoerced === 0) {
            $analysis->checkpoint->clear();
            $analysis->buffer->flush(); // settled verdicts are real even when there is nothing to say
            $this->ledger->snapshot($window, $allGroups, $totals);
            $this->blindNotice($blind(true), $key, $endKey);
            $this->ledger->runRecord($runKey, RunOutcome::Quiet, $started, $this->blindCode);
            $this->advanceHighWater($window, $keys);

            return new RunResult(RunOutcome::Quiet);
        }

        Delivery::run(
            $c,
            $read,
            $routed->findings,
            $keys,
            $this->state,
            $this->notifier,
            $this->options,
            function (array $findings, ?string $routedNotice, int $draftsSkipped) use ($read, $analysis, $selection, $environment): Message {
                return $this->buildDigest($read, $analysis, $selection, $environment, $findings, $routedNotice, $draftsSkipped);
            },
            $this->out,
            function (DeliveryFailed $e) use ($environment): void {
                $this->announceDigestFailure($e, $environment);
            },
            function (string $kind): void {
                $this->observer?->kind($kind);
            },
            $analysis->byFingerprint,
            self::DELIVERED_TTL,
            $this->clock->now(),
            fn (Message $m): Message => $this->digestMessage($m, $keys),
        );

        $now = $this->clock->now()->format(DATE_ATOM);
        $this->blindNotice($blind(false, true), $key, $endKey);
        foreach ($routed->findings as $finding) {
            foreach ($finding->fingerprints as $fp) {
                $analysis->buffer->put($keys->reported($fp), ['lastReportedAt' => $now, 'atCount' => $analysis->byFingerprint[$fp]->count ?? 0], $retention);
            }
        }
        $analysis->checkpoint->clear();
        $analysis->buffer->flush();
        $this->ledger->snapshot($window, $allGroups, $totals);
        $this->ledger->runRecord($runKey, RunOutcome::Reported, $started);
        $this->advanceHighWater($window, $keys);

        return new RunResult(RunOutcome::Reported);
    }

    /**
     * Redacts and truncates the candidates, reads the dependencies, runs the models.
     *
     * @param list<Candidate> $candidates
     *
     * @throws AnalyzerFailed after the window snapshot is kept, so the next trigger retries
     */
    private function analyze(WindowRead $read, array $candidates, Normalizer $normalizer, Redactor $redactor, WindowKeys $keys, DateTimeImmutable $started, ?BufferedStateStore $buffer = null): Analysis
    {
        $c = $this->config;
        $key = $c->stateKey;
        $retention = $c->state['retention'];
        $window = $read->window;
        $allGroups = $read->allGroups;
        $totals = $read->totals;

        // Truncate by candidate priority first, so the lowest-priority groups are the ones dropped (FR-021).
        $redactedBy = [];
        foreach ($candidates as $cand) {
            $redactedBy[$cand->group->fingerprint] = $redactor->redact($cand->group);
        }
        $annotations = $this->fleet?->reduced($key, array_values($redactedBy)) ?? [];
        $serviceContext = $this->serviceContext($redactor);
        $firstStep = $c->on('triage') ? 'triage' : 'analyze';
        $truncation = Budget::truncate(
            $candidates,
            $c->analysis['max_input_tokens'][$firstStep],
            static fn (array $cs): string => CompactPayload::encode(array_map(static fn ($cand) => $redactedBy[$cand->group->fingerprint], $cs)),
            Budget::fixedTokens($firstStep, $serviceContext, $c->on('advice')),
        );
        $redacted = array_map(static fn ($cand) => $redactedBy[$cand->group->fingerprint], $truncation->kept);

        [$dependencies, $failedDependencies, $undeclared] = $this->readDependencies($window, $normalizer, $redactor, array_map(static fn (RedactedGroup $g): ?string => $g->template, $redacted));
        $dependencies = Budget::fitDependencies($redacted, $dependencies, $c->analysis['max_input_tokens']['analyze'], Budget::fixedTokens('analyze', $serviceContext, $c->on('advice')));
        $endpoints = $this->apiEndpoints($redacted, $dependencies, $serviceContext, $redactor);

        $metrics = Budget::fitMetrics($redacted, $read->payloadExtras, $c->analysis['max_input_tokens']['analyze'], $serviceContext, $c->on('advice'), $dependencies);

        $this->renewLock();
        $buffer = $buffer ?? new BufferedStateStore($this->state); // verdicts, reported markers, known refs: kept only once the digest is delivered
        $checkpoint = new TriageCheckpoint($this->state, $key, $keys->end());
        $router = new ModelRouter(self::counted(self::renewing(self::redacting($this->analyzer, $redactor), $this->renewLock(...)), $this->options->estimate ? null : $this->ledger->stats(), $c->analysis['models']), new VerdictCache($buffer, $key, $retention), $this->routerSettings($serviceContext, $metrics), $checkpoint);
        try {
            $routed = $router->route($redacted, $dependencies, $endpoints->model);
        } catch (AnalyzerFailed $e) {
            // The window was read: keep it as the next window's comparison base. Normally no RunRecord, so the next trigger retries;
            // but a refusal, or a second failure of this window, is recorded as failed so hourly triggers do not re-bill it endlessly.
            $failures = 0;
            try {
                $this->ledger->snapshot($window, $allGroups, $totals);
                $failuresKey = $keys->analysisFailures();
                $failures = (int) ($this->state->get($failuresKey)['n'] ?? 0) + 1;
                $this->state->put($failuresKey, ['n' => $failures], $retention);
            } catch (Throwable) {
                // A state fault must not replace the analysis failure the run will report.
            }
            if ($e->refused || $failures >= self::MAX_ANALYSIS_FAILURES) {
                $this->ledger->runRecord($keys->run(), RunOutcome::Failed, $started, $e->refused ? 'analysis_refused' : 'analysis_failed');

                throw new AnalyzerFailed($e->getMessage() . ' (no further attempts for this window)', $e->refused);
            }

            throw $e;
        }

        $this->ledger->stats()->findings = count($routed->findings);

        $byFingerprint = [];
        foreach ($redacted as $g) {
            $byFingerprint[$g->fingerprint] = $g;
        }

        $analysis = new Analysis(routed: $routed, truncation: $truncation, redacted: $redacted, byFingerprint: $byFingerprint, undeclared: $undeclared, failedDependencies: $failedDependencies, annotations: $annotations, buffer: $buffer, checkpoint: $checkpoint, endpoints: $endpoints);
        $this->ledger->observeAnalysis($analysis);

        return $analysis;
    }

    /**
     * Spec 010: one digest line and one short `ep` field per group whose template names a documented route. Reads the OpenAPI
     * description only when some template names a route; any failure is a fixed token, never an exception (the run goes on).
     * `Budget::fitEndpoints` runs after `fitDependencies`: the `ep` fields are the first context dropped.
     *
     * @param list<RedactedGroup>     $redacted
     * @param list<DependencyContext> $dependencies
     */
    private function apiEndpoints(array $redacted, array $dependencies, string $serviceContext, Redactor $redactor): EndpointContext
    {
        $a = $this->config->apiContext;
        try {
            $reader = $this->apiContext === null ? null : ($this->apiContext)($this->state, $redactor);
            $resolver = new EndpointResolver($redactor, $a['max_endpoints'] ?? 1, $a['ignore_prefixes'] ?? []);
            if ($reader === null || !$resolver->anyRoute($redacted)) {
                return new EndpointContext();
            }
            $read = $reader->spec();
            if ($read->spec === null) {
                $this->hostLog?->warning('API context unavailable: ' . ($read->failure ?? 'unreadable'), ['service' => $this->config->service]);

                return EndpointContext::unavailable($read->failure ?? 'unreadable');
            }
            $ctx = $resolver->resolve($redacted, $read->spec, $read->cache);
            $kept = Budget::fitEndpoints($redacted, $dependencies, $ctx->model, $this->config->analysis['max_input_tokens']['analyze'], Budget::fixedTokens('analyze', $serviceContext, $this->config->on('advice'), true));

            return $kept === $ctx->model ? $ctx : $ctx->withoutModelField();
        } catch (Throwable) {
            return EndpointContext::unavailable('unreadable');
        }
    }

    /** The digest message: findings, what was left out and why, links. */
    /**
     * @param list<\Watcher\Analyze\Finding>|null $findingsOverride
     */
    private function buildDigest(WindowRead $read, Analysis $analysis, SelectionResult $selection, string $environment, ?array $findingsOverride = null, ?string $routedNotice = null, int $draftsSkipped = 0): Message
    {
        $c = $this->config;
        $service = $c->service;
        $window = $read->window;
        $deploy = $read->deploy;
        $totals = $read->totals;
        $anomalies = $read->anomalies;
        $collector = $read->collector;
        $muteResult = $read->muteResult;
        $comparison = $read->comparison;
        $comparedWith = $read->comparedWith;
        $patternFailures = $read->patternFailures;
        $routed = $analysis->routed;
        $findings = $findingsOverride ?? $routed->findings;
        $redacted = $analysis->redacted;
        $truncation = $analysis->truncation;
        $undeclared = $analysis->undeclared;
        $failedDependencies = $analysis->failedDependencies;
        $annotations = $analysis->annotations;

        $logLinks = $c->on('links.logs') ? new LogLinkBuilder($c->links, $c->source['stream_tags']) : null;
        $traceLinks = $read->traces === null ? null : new TraceLinkBuilder($c->links, array_map('strval', $c->source['stream_tags']), $service);
        $codeLinks = $c->on('links.code') ? new CodeLinkBuilder($c->repo ?? [], $deploy->commit) : null;
        $links = [];
        $afterRelease = [];
        foreach ($redacted as $g) {
            // A pod event is not in the log stream, so a log query for it would find nothing.
            $issue = str_starts_with($g->fingerprint, 'sentry:') ? $c->sentry : null; // a Sentry entry links to its issue, built from config and the numeric id, never fetched
            $links[$g->fingerprint] = ['log' => $issue === null && $g->origin === null ? $logLinks?->forGroup($g, $window) : null, 'code' => $codeLinks?->forGroup($g) ?? [], 'trace' => $issue === null && !str_starts_with($g->fingerprint, 'metric:') ? $traceLinks?->forGroup($read->traces?->idFor($g->fingerprint), $window) : null, 'sentry' => $issue === null ? null : rtrim($issue->baseUrl, '/') . '/organizations/' . rawurlencode($issue->organization) . '/issues/' . rawurlencode(substr($g->fingerprint, 7)) . '/'];
            $mark = $deploy->firstSeenAfterLabel($g->status);
            if ($mark !== null) {
                $afterRelease[$g->fingerprint] = $mark;
            }
        }
        $this->ledger->observation()->newAfterRelease = array_map('strval', array_keys($afterRelease));

        return (new DigestRenderer())->render(new DigestData(
            service: $service,
            environment: $environment,
            windowStart: $window->start,
            windowEnd: $window->end,
            timezone: $c->schedule['timezone'],
            findings: $findings,
            groups: $analysis->byFingerprint,
            release: $deploy->release,
            levelAnomalyLine: $this->anomalyLine($anomalies, $read->previousTotals, $totals),
            eventsReadTruncated: $collector->truncated() ? $collector->eventsRead() : null,
            droppedGroups: $truncation->droppedCount,
            otherGroups: $selection->otherGroups,
            otherEvents: $selection->otherEvents,
            muted: $muteResult->mutedCount,
            afterReleaseFingerprints: $afterRelease,
            undeclaredHostCount: $undeclared,
            failedDependencies: $failedDependencies,
            links: $links,
            overflowedEvents: $collector->overflowedEvents(),
            groupCap: $c->source['group_cap'],
            mutePartial: $muteResult->partial,
            notAnalysed: $routed->notAnalysed,
            patternFailures: $patternFailures,
            escalationsSkipped: $routed->escalationsSkippedSize,
            comparedWith: $comparison->usedMode === 'previous' ? $comparedWith : null,
            annotations: $annotations,
            comparison: $comparison,
            mining: $collector->mining(),
            readDiagnostics: ($this->source instanceof ReadDiagnostics ? $this->source->readDiagnostics() : []) + ($this->ledger->stats()->oversizedLines > 0 ? ['oversized_lines' => $this->ledger->stats()->oversizedLines] : []) + ($this->ledger->stats()->unscannableEvents > 0 ? ['unscannable_events' => $this->ledger->stats()->unscannableEvents] : []),
            reusedGroups: $routed->reused,
            triageDropped: $routed->triageDropped,
            triageCoerced: $routed->triageCoerced,
            unreported: $routed->unreported,
            newOverflow: $routed->newOverflow,
            extras: $read->extras,
            endpoints: $analysis->endpoints,
            knownLine: $read->knownLine,
            findingLabels: $read->knownResult !== null ? $read->knownResult->labels : [],
            routedNotice: $routedNotice,
            draftsSkipped: $draftsSkipped,
            knownCount: $read->knownResult !== null ? $read->knownResult->knownCount : 0,
            knownGrownCount: $read->knownResult !== null ? $read->knownResult->knownGrownCount : 0,
            gapFrom: $this->scheduleMeta['gapFrom'] ?? null,
            gapTo: $this->scheduleMeta['gapTo'] ?? null,
            gapWindows: $this->scheduleMeta['gapWindows'] ?? null,
            catchUpBatch: $this->scheduleMeta['catchUpBatch'] ?? null,
        ), $c->analysis['confidence_threshold']);
    }

    private function digestMessage(Message $message, WindowKeys $keys): Message
    {
        if ($this->options->dryRun) {
            return $message;
        }
        $partsKey = $keys->deliveredParts();
        $skip = (int) ($this->state->get($partsKey)['n'] ?? 0);

        return new Message($message->text, $message->sections, new DeliveryProgress($skip, function (int $n) use ($partsKey): void {
            $this->state->put($partsKey, ['n' => $n], self::DELIVERED_TTL);
        }));
    }

    /** Renews the run lock before every model call (A4-05). */
    private static function renewing(Analyzer $inner, \Closure $renew): Analyzer
    {
        return new class ($inner, $renew) implements Analyzer {
            public function __construct(private readonly Analyzer $inner, private readonly \Closure $renew)
            {
            }

            public function triage(TriageRequest $req): TriageResult
            {
                ($this->renew)();

                return $this->inner->triage($req);
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                ($this->renew)();

                return $this->inner->analyze($req);
            }
        };
    }

    /**
     * Consecutive windows in which the source returned no event and no level total at all; 0 when it saw anything or totals are off.
     *
     * @param array<string, int> $totals
     */
    private function zeroStreak(string $key, int $eventsRead, array $totals): int
    {
        if (!$this->config->on('level_anomaly')) {
            return 0; // without totals an empty read cannot be told from a healthy quiet service
        }
        $zeroKey = Keys::zeroRead($key);
        try {
            $n = (int) ($this->state->get($zeroKey)['n'] ?? 0);
            $next = $eventsRead === 0 && array_sum($totals) === 0 ? $n + 1 : 0;
            if ($next !== $n) {
                $this->state->put($zeroKey, ['n' => $next], Keys::ttl(Keys::REPORTED, $this->config->state['retention'], 0));
            }

            return $next;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * What the run could not see, as one line of counts, or null. Cap truncation and overflow are already in a sent digest,
     * so they are only named here for a quiet run; a deadline, memory stop, cursor stop, dropped line or empty source always are.
     */
    private function blindLine(bool $quiet, bool $digestSent, ?string $reason, int $eventsRead, int $overflow, int $zeroStreak): ?string
    {
        $stats = $this->ledger->stats();
        $parts = [];
        $code = null;
        if ($reason !== null && ($quiet || $reason !== 'cap')) {
            $parts[] = sprintf('read truncated (%s) after %d events', $reason, $eventsRead);
            $code = 'truncated:' . $reason;
        }
        if ($quiet && $overflow > 0) {
            $parts[] = sprintf('%d events folded into overflow groups', $overflow);
            $code ??= 'overflow';
        }
        if ($stats->oversizedLines > 0) {
            $parts[] = sprintf('%d lines over 64 KB dropped', $stats->oversizedLines);
            $code ??= 'oversized_lines';
        }
        if ($stats->unscannableEvents > 0 && !$digestSent) { // a sent digest already carries it in its read-quality line
            $parts[] = sprintf('%d events could not be scanned', $stats->unscannableEvents);
            $code ??= 'unscannable_events';
        }
        if ($zeroStreak >= self::ZERO_WINDOWS) {
            $parts[] = sprintf('0 events read for %d consecutive windows', $zeroStreak);
            $code ??= 'zero_events';
        }
        if ($parts === []) {
            return null;
        }
        $this->blindCode = $code;
        $this->ledger->setCode($this->ledger->code() ?? $code);

        return sprintf('⚠️ watcher · %s · %s — incomplete read: %s', SlackText::escape($this->config->service), SlackText::escape($this->environment()), implode('; ', $parts));
    }

    /**
     * A window cut by the deadline or the memory guard has unread or unanalysed groups, so it is not done: the first cut
     * leaves a marker and no run record (the next trigger in the due period reads the window again with a fresh run deadline).
     * A second cut is final: true means "retry", false means record it, after a loud notice (counts only).
     */
    private function retryCutWindow(WindowKeys $keys, int $retention, int $unanalysed, ?string $line): bool
    {
        $marker = $keys->partial();
        if ((int) ($this->state->get($marker)['n'] ?? 0) < 1) {
            $this->state->put($marker, ['n' => 1], $retention);
            $this->blindNotice($line, $this->config->stateKey, $keys->end());

            return true;
        }
        if ($this->config->on('notice')) {
            $text = sprintf('⚠️ watcher · %s · %s — window cut again after a retry (%s): %d candidate groups were not analysed, recorded as final', SlackText::escape($this->config->service), SlackText::escape($this->environment()), $this->blindCode ?? 'truncated', $unanalysed);
            try {
                $this->noticeOnce('blindfinal:' . rawurlencode($this->config->stateKey) . ':' . $keys->end(), self::plainNotice($text), $this->noticeDestination());
            } catch (Throwable $e) {
                $this->hostLog?->warning('incomplete-read notice not delivered: ' . $e::class, ['service' => $this->config->service]);
            }
        }

        return false;
    }

    /** Once per window; a delivery fault is logged, never fatal. */
    private function gapNotice(string $key, string $endKey): void
    {
        $m = $this->scheduleMeta;
        if ($m === null || $m['gapFrom'] === null || $m['gapTo'] === null || !$this->config->on('notice')) {
            return;
        }
        $this->noticeOnce('gap:' . rawurlencode($key) . ':' . $endKey, self::plainNotice(DigestRenderer::formatGapLine($m['gapFrom'], $m['gapTo'], $m['gapWindows'])), $this->noticeDestination());
    }

    private function blindNotice(?string $line, string $key, string $endKey): void
    {
        $this->gapNotice($key, $endKey);
        if ($line === null || !$this->config->on('notice')) {
            return;
        }
        try {
            $this->noticeOnce('blind:' . rawurlencode($key) . ':' . $endKey, self::plainNotice($line), $this->noticeDestination());
        } catch (Throwable $e) {
            $this->hostLog?->warning('incomplete-read notice not delivered: ' . $e::class, ['service' => $this->config->service]);
        }
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

                return new AnalysisResult(array_map($this->redactor->redactFinding(...), $r->findings));
            }
        };
    }

    /**
     * Counts each analyzer call that returned a reply into the run's call counts (the event stream and the summary).
     *
     * @param array<string, string> $models configured model per step, to tell an escalation from the main analysis
     */
    private static function counted(Analyzer $inner, ?RunStats $stats, array $models): Analyzer
    {
        return new class ($inner, $stats, $models) implements Analyzer {
            /** @param array<string, string> $models */
            public function __construct(private readonly Analyzer $inner, private readonly ?RunStats $stats, private readonly array $models)
            {
            }

            public function triage(TriageRequest $req): TriageResult
            {
                try {
                    $r = $this->inner->triage($req);
                } catch (AnalyzerFailed $e) {
                    $this->stats?->addCall('triage'); // a failed reply was still a model call
                    throw $e;
                }
                $this->stats?->addCall('triage');

                return $r;
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                try {
                    $r = $this->inner->analyze($req);
                } catch (AnalyzerFailed $e) {
                    $this->stats?->addCall($this->analyzeStep($req)); // a failed reply was still a model call
                    throw $e;
                }
                $this->stats?->addCall($this->analyzeStep($req));

                return $r;
            }

            /** @return 'analyze'|'escalate' */
            private function analyzeStep(AnalysisRequest $req): string
            {
                $escalate = $this->models['escalate'] ?? null;

                return $escalate !== null && $req->model === $escalate && $escalate !== ($this->models['analyze'] ?? null) ? 'escalate' : 'analyze';
            }
        };
    }

    /** FR-040: the service context passes the same redaction scan as templates; unscannable means empty, never raw. */
    private function serviceContext(Redactor $redactor): string
    {
        if ($this->config->serviceContext === '' || !$this->config->on('service_context')) {
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
        if (!$c->on('dependencies')) {
            return [[], [], 0];
        }
        $current = [];
        $previous = [];
        $declared = [];
        $failed = [];
        foreach ($c->dependencies as $name => $dep) {
            $name = (string) $name;
            $snap = $this->state->get(Keys::dependencySnapshot($c->stateKey, $name, $this->previousKey));
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
            $buffer = $this->ledger->deferred();
            try {
                $reader = new DependencyContextReader($normalizer, $redactor, $buffer, $c->state['retention'], $c->minGroupEventsForText);
                $contexts = $reader->read($c->stateKey, $declared, fn (string $name): LogSource => $this->source, $plan, $window, $c->dependencyReadCap, $previous, $current)->contexts;
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

    private function routerSettings(string $serviceContext, ?PayloadExtras $metrics = null): RouterSettings
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
            $this->config->on('escalation') ? $a['escalation_cap'] : 0,
            $a['confidence_threshold'],
            $this->config->selection['growth_factor'],
            $serviceContext,
            $a['max_input_tokens']['escalate'],
            $this->config->on('triage'),
            $this->config->on('advice'),
            bin2hex(random_bytes(8)), // top_m tie-break: a fingerprint cannot be ground to sort first
            $metrics,
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
        if (!$this->state->acquire($key, $owner, Keys::ttl(Keys::FAILNOTICE, 0, 0))) {
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
     * H6: the snapshot the last successful snapshot write points to, if it is older than this window's start but at most baseline.max_age before its end.
     *
     * @return array{0: array<mixed>, 1: DateTimeImmutable, 2: string}|array{}
     */
    private function lastGoodSnapshot(Window $window): array
    {
        $key = $this->state->get(Keys::lastSnapshot($this->config->stateKey))['end'] ?? null;
        $end = is_string($key) ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $key, new \DateTimeZone('UTC')) : false;
        if ($end === false || $end >= $window->end || $window->end->getTimestamp() - $end->getTimestamp() > $this->config->baseline['max_age_seconds']) {
            return [];
        }
        $snap = $this->state->get(Keys::snapshot($this->config->stateKey, $key));
        if (!is_array($snap) || !Fingerprinter::snapshotUsable($snap)) {
            return [];
        }

        return [$snap, $end, $key];
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
