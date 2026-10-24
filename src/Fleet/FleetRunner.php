<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Operate\Heartbeat;
use Watcher\Operate\RunOptions;
use Watcher\RunOutcome;
use Watcher\RunResult;
use Watcher\Schedule\SystemClock;
use Watcher\Watcher;
use Watcher\State\StateStore;

/**
 * Runs the services of a fleet one after another, each in isolation: a throwable in one becomes a failed entry and
 * never stops the others. The unit of work stays one Watcher per service (spec 003, plan R1).
 */
final class FleetRunner
{
    /**
     * @param Closure(ServiceEntry, RunOptions, ?object): (callable(?DateTimeImmutable): RunResult) $watcherFor
     *        builds the runnable for one entry; the third argument is the fleet seam (always null until FleetSeam exists)
     * @param StateStore $state the store shared by the fleet; only the fleet heartbeat is written through it here
     */
    public function __construct(
        private readonly FleetConfig $config,
        private readonly Closure $watcherFor,
        private readonly StateStore $state,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $log = null,
    ) {
    }

    /**
     * Everything shared is built once: one StateStore, one Analyzer; per entry a source. Throws InvalidConfig before
     * any external call (a bad fleet runs nothing).
     *
     * @param array<string, mixed>|string $config a fleet array, or the path of a fleet file or directory
     * @param callable(string): mixed|null $out dry-run output sink (default: stdout)
     */
    public static function fromConfig(array|string $config, ?LoggerInterface $log = null, ?ClockInterface $clock = null, mixed $out = null): self
    {
        $fleet = is_array($config) ? FleetConfig::fromArray($config) : FleetConfig::fromPath($config);
        $clock ??= new SystemClock();
        $http = Factory::http();
        $first = $fleet->services[0]->config;
        $state = Factory::state($first, $clock);
        $analyzer = Factory::analyzer($first);
        $notifier = Factory::notifier($http);
        $sources = [];
        foreach ($fleet->services as $e) {
            $sources[$e->name()] = Factory::source($e->config, $http, $clock);
        }

        $watcherFor = static fn (ServiceEntry $e, RunOptions $options, ?object $seam = null): callable => (new Watcher(
            $e->config,
            $sources[$e->name()],
            $state,
            $analyzer,
            $notifier,
            $clock,
            $log,
            $options,
            $out,
        ))->run(...);

        return new self($fleet, $watcherFor, $state, $clock, $log);
    }

    /**
     * @param list<string> $only run just these services (names must exist); empty = all
     * @throws InvalidConfig for an unknown name in $only
     */
    public function run(?DateTimeImmutable $now, RunOptions $options, array $only = []): FleetResult
    {
        $unknown = array_diff($only, array_map(static fn (ServiceEntry $e): string => $e->name(), $this->config->services));
        if ($unknown !== []) {
            throw new InvalidConfig('--only names a service that is not in the fleet');
        }
        $startedAt = $this->clock->now();
        $now ??= $startedAt; // one instant for the whole trigger
        $deadline = $this->config->timeBudgetSeconds === null ? null : $startedAt->modify('+' . $this->config->timeBudgetSeconds . ' seconds');

        $results = [];
        foreach ($this->config->inOrder() as $entry) {
            if ($only !== [] && !in_array($entry->name(), $only, true)) {
                continue;
            }
            if ($deadline !== null && $this->clock->now() >= $deadline) {
                $results[] = ServiceResult::skippedTime($entry->name());
                continue;
            }
            $results[] = $this->runOne($entry, $now, $options);
        }
        if (!$options->dryRun) {
            $this->heartbeat($results, $startedAt);
        }

        return new FleetResult($this->config->name, $results);
    }

    private function runOne(ServiceEntry $entry, DateTimeImmutable $now, RunOptions $options): ServiceResult
    {
        try {
            return ServiceResult::ran($entry->name(), (($this->watcherFor)($entry, $options, null))($now));
        } catch (Throwable $e) {
            $this->log?->warning('service failed: ' . $e::class, ['service' => $entry->name()]); // class only, never the message

            return ServiceResult::crashed($entry->name(), $e);
        }
    }

    /** @param list<ServiceResult> $results */
    private function heartbeat(array $results, DateTimeImmutable $startedAt): void
    {
        $outcomes = array_column(array_map(static fn (ServiceResult $r): array => ['o' => $r->outcome], $results), 'o');
        $failed = count(array_filter($results, static fn (ServiceResult $r): bool => $r->exitCode() !== 0));
        $outcome = match (true) {
            $failed > 0 => RunOutcome::Failed,
            in_array('reported', $outcomes, true) => RunOutcome::Reported,
            in_array('quiet', $outcomes, true) || in_array('baselined', $outcomes, true) => RunOutcome::Quiet,
            default => null, // only skips: a not-due, done or locked fleet trigger records nothing
        };
        if ($outcome === null) {
            return;
        }
        $first = $this->config->services[0]->config;
        $secrets = [];
        foreach ($this->config->services as $e) {
            $secrets = [...$secrets, (string) $e->config->analysis['api_key'], $e->config->notify['digest']['webhook'], $e->config->notify['notice']['webhook']];
        }
        $now = $this->clock->now();
        (new Heartbeat(
            $this->state,
            $this->clock,
            'fleet/' . $this->config->name,
            new DateTimeZone($this->config->timezone),
            $first->state['retention'],
            $this->log,
            $secrets,
        ))->recordRun($outcome, $startedAt, $now, $startedAt, 0.0, $failed > 0 ? $failed . ($failed === 1 ? ' service failed' : ' services failed') : null);
    }
}
