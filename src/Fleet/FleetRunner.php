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
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\NoticeRenderer;
use Watcher\Notify\Notifier;
use Watcher\Operate\DryRunStateStore;
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
     * @param Closure(ServiceEntry, RunOptions, ?FleetSeam): (callable(?DateTimeImmutable): RunResult) $watcherFor
     *        builds the runnable for one entry; the third argument is the fleet seam of this run (pass it to the Watcher and
     *        take its notifier from `$seam->notifierFor()`)
     * @param StateStore $state the store shared by the fleet: fleet counters, fleet alerts and the fleet heartbeat
     * @param Notifier|null $notifier what the fleet itself posts (budget notices, combined digest); null = posts nothing
     */
    public function __construct(
        private readonly FleetConfig $config,
        private readonly Closure $watcherFor,
        private readonly StateStore $state,
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $log = null,
        private readonly ?Notifier $notifier = null,
        private readonly mixed $out = null,
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
        $state = Factory::state($first, $clock, $log);
        $analyzer = Factory::analyzer($first);
        $notifier = Factory::notifier($http, $first->schedule['lock_ttl']);
        $sources = [];
        foreach ($fleet->services as $e) {
            $sources[$e->name()] = Factory::source($e->config, $http, $clock);
        }

        $share = $fleet->serviceTimeBudgetSeconds(); // A4-12: one slow service cannot take the whole fleet's time
        $watcherFor = static function (ServiceEntry $e, RunOptions $options, ?FleetSeam $seam = null) use ($sources, $state, $analyzer, $notifier, $clock, $log, $out, $share): callable {
            $watcher = new Watcher(
                $e->config,
                $sources[$e->name()],
                $state,
                $analyzer,
                $seam?->notifierFor($e->name(), $notifier) ?? $notifier,
                $clock,
                $log,
                $options,
                $out,
                $seam,
            );
            if ($share !== null) {
                $watcher->limitRunTo($share);
            }

            return $watcher->run(...);
        };

        return new self($fleet, $watcherFor, $state, $clock, $log, $notifier, $out);
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
        if (!$this->config->enabled) {
            // The fleet switch: no store, budget, source or notifier is reached.
            return new FleetResult($this->config->name, array_map(static fn (ServiceEntry $e): ServiceResult => ServiceResult::disabled($e->name()), array_values(array_filter($this->config->inOrder(), static fn (ServiceEntry $e): bool => $only === [] || in_array($e->name(), $only, true)))));
        }
        $startedAt = $this->clock->now();
        $now ??= $startedAt; // one instant for the whole trigger
        $deadline = $this->config->timeBudgetSeconds === null ? null : $startedAt->modify('+' . $this->config->timeBudgetSeconds . ' seconds');

        // A dry run reads the fleet counters but never writes them, and the fleet posts nothing.
        $b = $this->config->budget;
        $budget = new FleetBudget($options->dryRun ? new DryRunStateStore($this->state) : $this->state, $this->clock, new DateTimeZone($this->config->timezone), $this->config->name, $b['daily_budget_usd'], $b['daily_token_limit'], $b['alert_percents']);
        $correlator = new Correlator($this->config->services, $this->config->correlationToleranceSeconds, $this->config->correlationEnabled);
        $seam = new FleetSeam($budget, $this->config->digest === 'combined', $correlator);

        $results = [];
        foreach ($this->config->inOrder() as $entry) {
            if ($only !== [] && !in_array($entry->name(), $only, true)) {
                continue;
            }
            if (!$entry->config->enabled) {
                $results[] = ServiceResult::disabled($entry->name());
                continue;
            }
            if ($deadline !== null && $this->clock->now() >= $deadline) {
                $results[] = ServiceResult::skippedTime($entry->name());
                continue;
            }
            $before = $budget->today();
            $results[] = $this->runOne($entry, $now, $options, $seam);
            if (!$options->dryRun) {
                $this->alert($budget, $before);
            }
        }
        $combinedPosted = $this->postCombined($seam, $results, $options->dryRun);
        $correlated = $seam->correlated($this->config->name);
        $correlatedPosted = $this->postCorrelated($correlated, $options->dryRun);
        if (!$options->dryRun) {
            $this->heartbeat($results, $startedAt);
        }

        return new FleetResult($this->config->name, $results, $correlated, $correlatedPosted, $combinedPosted, $budget->today()['usd']);
    }

    /** Where the fleet's own messages go: its notice destination, else its digest one; null = nowhere. */
    private function destination(): ?Destination
    {
        return $this->notifier !== null ? $this->config->destination('notice') : null;
    }

    private function print(string $kind, Message $message): void
    {
        $text = $message->text;
        ($this->out ?? static function (string $s): void {
            fwrite(STDOUT, $s);
        })("== DRY RUN message to $kind ==\n" . $text . (str_ends_with($text, "\n") ? '' : "\n"));
    }

    /** @param array{usd: float, tokens: int} $before */
    private function alert(FleetBudget $budget, array $before): void
    {
        $to = $this->destination();
        $alert = $to === null ? null : $budget->alert($before, $budget->today());
        if ($to === null || $alert === null || $this->notifier === null) {
            return;
        }
        try {
            $text = $alert->noticeText('fleet ' . $this->config->name);
            $this->notifier->send(new Message($text, [['kind' => 'notice', 'text' => $text]]), $to);
        } catch (Throwable $e) {
            $budget->release($alert);
            $this->log?->warning('fleet budget alert not delivered: ' . $this->describe($e), ['fleet' => $this->config->name]);
        }
    }

    /**
     * Posts the combined digest once the services are done (a dry run prints it); null when there was nothing to post
     * (not combined, or no service reported, or a dry run).
     *
     * @param list<ServiceResult> $results
     */
    private function postCombined(FleetSeam $seam, array $results, bool $dryRun): ?bool
    {
        $to = $this->destination();
        $quiet = array_map(static fn (ServiceResult $r): string => $r->service, array_filter($results, static fn (ServiceResult $r): bool => $r->outcome === RunOutcome::Quiet->value));
        $message = $to === null || $this->notifier === null ? null : (new CombinedDigest())->build($this->config->name, $seam->digests(), array_values($quiet));
        if ($message === null) {
            return null;
        }
        if ($dryRun) {
            $this->print('digest', $message);

            return null;
        }
        try {
            $this->notifier->send($message, $to);

            return true;
        } catch (Throwable $e) {
            $this->log?->warning('combined digest not delivered: ' . $this->describe($e), ['fleet' => $this->config->name]);

            return false;
        }
    }

    /**
     * One additive message per origin group, to the fleet digest destination (a dry run prints them). A failed post is
     * loud (host log, false -> exit 1) and removes nothing: every consumer digest already carries its annotation.
     * Null when there is nothing to post or nowhere to post it.
     *
     * @param list<CorrelatedFinding> $findings
     */
    private function postCorrelated(array $findings, bool $dryRun): ?bool
    {
        $to = $this->config->destination('digest');
        if ($findings === [] || $this->notifier === null || $to === null) {
            return null;
        }
        $renderer = new CorrelationRenderer();
        $posted = true;
        foreach ($findings as $f) {
            $message = $renderer->render($f, $this->config->timezone);
            if ($dryRun) {
                $this->print('correlated finding', $message);
                continue;
            }
            try {
                $this->notifier->send($message, $to);
            } catch (Throwable $e) {
                $posted = false;
                $this->log?->warning('correlated finding not delivered: ' . $e::class, ['fleet' => $this->config->name]);
            }
        }

        return $dryRun ? null : $posted;
    }

    private function runOne(ServiceEntry $entry, DateTimeImmutable $now, RunOptions $options, FleetSeam $seam): ServiceResult
    {
        try {
            return ServiceResult::ran($entry->name(), (($this->watcherFor)($entry, $options, $seam))($now));
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
        if (!$first->on('heartbeat')) {
            return;
        }
        $secrets = [];
        foreach ($this->config->services as $e) {
            $secrets = [...$secrets, ...$e->config->secrets()];
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

    /** Class name and a masked message for the host log: a delivery error can echo a webhook URL or token. */
    private function describe(Throwable $e): string
    {
        $secrets = [];
        foreach ($this->config->services as $entry) {
            array_push($secrets, ...$entry->config->secrets());
        }
        foreach ($this->config->notify as $block) {
            foreach (is_array($block) ? $block : [] as $v) {
                if (is_string($v)) {
                    $secrets[] = $v;
                }
            }
        }

        return (new NoticeRenderer(array_values(array_unique($secrets))))->describe($e);
    }
}
