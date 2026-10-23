<?php

declare(strict_types=1);

namespace Watcher;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Usage;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\DigestData;
use Watcher\Notify\DigestRenderer;
use Watcher\Notify\Notifier;
use Watcher\Privacy\RedactedGroup;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;
use Watcher\Reduce\Reducer;
use Watcher\Schedule\SystemClock;
use Watcher\Schedule\Window;
use Watcher\Select\LevelAnomaly;
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
        $window = Window::endingAt($now ?? $this->clock->now(), $c->schedule['window']);
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

        $selector = new StreamSelector($c->source['stream_tags']);
        $readMin = $c->source['read_min_level'];
        $totals = $this->source->totals($window, $selector);

        /** @var array{groups?: array<string, int>, totals?: array<string, int>}|null $prev */
        $prev = $this->state->get(Keys::snapshot($service, self::windowKey($window->start)));
        $anomalies = LevelAnomaly::levels($prev === null ? null : ($prev['totals'] ?? []), $totals, $c->selection['growth_factor'], $readMin);

        $truncated = false;
        $events = (function () use ($window, $selector, $anomalies, $readMin, &$truncated): iterable {
            foreach ($anomalies as $level) {
                foreach ($this->source->lines($window, $selector, new ReadFilter($level, [], $this->config->source['anomaly_cap'])) as $e) {
                    if ($e->level === $level) {
                        yield $e;
                    }
                }
            }
            yield from $this->source->lines($window, $selector, new ReadFilter($readMin, [], $this->config->source['event_cap']));
            $truncated = $this->source->lastReadTruncated();
        })();

        $normalizer = new Normalizer(array_map('strval', array_keys($c->dependencies)), $c->dependencyDomains);
        $groups = (new Reducer($normalizer, $c->minGroupEventsForText))->reduce($events);
        $factor = $c->selection['growth_factor'];
        $previousGroups = $prev['groups'] ?? [];
        $groups = array_map(
            static fn (ProblemGroup $g): ProblemGroup => $g->withPrevious($prev === null ? null : ($previousGroups[$g->fingerprint] ?? null), $factor),
            $groups,
        );

        $environment = is_string($c->source['stream_tags']['env'] ?? null) ? $c->source['stream_tags']['env'] : 'default';
        $renderer = new DigestRenderer();

        if ($prev === null) {
            $this->writeSnapshot($window, $groups, $totals);
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
            $this->writeSnapshot($window, $groups, $totals);
            $this->writeRun($runKey, RunOutcome::Quiet, null, $started);

            return new RunResult(RunOutcome::Quiet);
        }

        $redacted = array_map(static fn ($cand): RedactedGroup => RedactedGroup::fromProblemGroup($cand->group), $candidates);
        $model = $c->analysis['models']['analyze'];
        $result = $this->analyzer->analyze(new AnalysisRequest($redacted, $c->serviceContext, $model, $c->analysis['max_output_tokens']['analyze']));

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
            $result->findings,
            $byFingerprint,
            null,
            $result->usage,
            ['analyze' => $model],
            $result->usage->costUsd,
            $this->anomalyLine($anomalies, $prev['totals'] ?? [], $totals),
            $truncated ? array_sum(array_map(static fn (ProblemGroup $g): int => $g->count, $groups)) : null,
            0,
            $selection->otherGroups,
            $selection->otherEvents,
        ), $c->analysis['confidence_threshold']);
        $this->notifier->send($message, new Destination($c->notify['digest']['webhook']));

        $now = $this->clock->now()->format(DATE_ATOM);
        foreach ($result->findings as $finding) {
            foreach ($finding->fingerprints as $fp) {
                $this->state->put(Keys::reported($service, $fp), ['lastReportedAt' => $now, 'atCount' => $byFingerprint[$fp]->count ?? 0], $retention);
            }
        }
        $this->writeSnapshot($window, $groups, $totals);
        $this->writeRun($runKey, RunOutcome::Reported, $result->usage, $started);

        return new RunResult(RunOutcome::Reported);
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
