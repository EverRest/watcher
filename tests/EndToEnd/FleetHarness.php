<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Watcher\Config\WatchConfig;
use Watcher\Fleet\FleetConfig;
use Watcher\Fleet\FleetRunner;
use Watcher\Fleet\FleetSeam;
use Watcher\Fleet\ServiceEntry;
use Watcher\Notify\Notifier;
use Watcher\Operate\RunOptions;
use Watcher\RunResult;
use Watcher\Source\LogSource;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Watcher;

/**
 * Three fake services (api, bff-a, bff-b) over ONE state store and ONE analyzer, a FakeNotifier and a FakeLogSource per
 * service. Fakes only, no network. `bff-b` has no notify of its own, so it uses the fleet default.
 */
final class FleetHarness
{
    public readonly FrozenClock $clock;
    public readonly InMemoryStateStore $state;
    public readonly EchoAnalyzer $analyzer;
    public ?LoggerInterface $hostLog = null;

    /** @var array<string, FakeNotifier> */
    public array $notifiers = [];

    /** @var array<string, FakeLogSource> */
    public array $sources = [];

    /** What the fleet itself posts (combined digest, fleet budget notices). */
    public FakeNotifier $fleetNotifier;

    /** Everything printed in a dry run (by the services and by the fleet). */
    public string $printed = '';

    /** Called after each service ran, with its name (e.g. to advance the clock). */
    public ?Closure $afterService = null;

    /** @var array<string, \Throwable> thrown by the watcher factory for that service */
    public array $crash = [];

    /**
     * @param list<string> $names
     * @param array<string, mixed> $fleetPatch
     * @param array<string, array<string, mixed>> $servicePatches
     */
    public function __construct(
        private readonly array $names = ['api', 'bff-a', 'bff-b'],
        private readonly array $fleetPatch = [],
        private readonly array $servicePatches = [],
    ) {
        class_exists(Harness::class); // loads EchoAnalyzer, declared in Harness.php
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00'));
        $this->state = new InMemoryStateStore($this->clock);
        $this->analyzer = new EchoAnalyzer();
        $this->fleetNotifier = new FakeNotifier();
        foreach ($names as $n) {
            $this->notifiers[$n] = new FakeNotifier();
            $this->sources[$n] = new FakeLogSource();
        }
    }

    /** @return array<string, mixed> a complete service entry */
    public function service(string $name): array
    {
        return array_replace_recursive([
            'service' => $name,
            'enabled' => true,
            'schedule' => ['run_at' => $this->clock->now()->format('H:i')],
            'source' => [
                'driver' => 'loki',
                'loki' => ['url' => 'http://loki.invalid'],
                'stream_tags' => ['app' => $name, 'env' => 'stg'],
                'anomaly_cap' => 100,
            ],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm-t', 'analyze' => 'm-a', 'escalate' => 'm-e'],
                'prices' => ['m-t' => [1, 5, 0.1, 1.25], 'm-a' => [3, 15, 0.3, 3.75], 'm-e' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/nonexistent']],
            'notify' => $name === 'bff-b' ? [] : ['digest' => ['webhook' => 'https://hooks.invalid/' . $name]],
        ], $this->servicePatches[$name] ?? []);
    }

    /** @return array<string, mixed> */
    public function fleetArray(): array
    {
        return array_replace([
            'fleet' => 'platform',
            'notify' => ['digest' => ['webhook' => 'https://hooks.invalid/fleet']],
            'services' => array_map(fn (string $n): array => $this->service($n), $this->names),
        ], $this->fleetPatch);
    }

    /** @param list<\Watcher\Source\LogEvent> $events */
    public function logs(string $service, array $events): void
    {
        $this->sources[$service] = new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /** The factory FleetRunner takes: one Watcher per entry over the shared state and analyzer. */
    public function watcherFor(): Closure
    {
        return function (ServiceEntry $e, RunOptions $options, ?FleetSeam $seam = null): callable {
            $name = $e->name();

            return function (?DateTimeImmutable $now) use ($e, $options, $name, $seam): RunResult {
                if (isset($this->crash[$name])) {
                    throw $this->crash[$name];
                }
                $result = (new Watcher($e->config, $this->sources[$name], $this->state, $this->analyzer, $seam?->notifierFor($name, $this->notifiers[$name]) ?? $this->notifiers[$name], $this->clock, $this->hostLog, $options, function (string $text): void {
                    $this->printed .= $text;
                }, $seam))->run($now);
                if ($this->afterService !== null) {
                    ($this->afterService)($name);
                }

                return $result;
            };
        };
    }

    public function runner(?StateStore $fleetState = null): FleetRunner
    {
        return new FleetRunner(FleetConfig::fromArray($this->fleetArray()), $this->watcherFor(), $fleetState ?? $this->state, $this->clock, $this->hostLog, $this->fleetNotifier, function (string $text): void {
            $this->printed .= $text;
        });
    }

    /**
     * The same service run alone, on its own fresh state, notifier and analyzer.
     *
     * @return array{RunResult, FakeNotifier, InMemoryStateStore}
     */
    public function single(string $name, LogSource $source): array
    {
        $state = new InMemoryStateStore($this->clock);
        $notifier = new FakeNotifier();
        $result = (new Watcher(WatchConfig::fromArray($this->service($name) + ['notify' => $this->fleetArray()['notify']]), $source, $state, new EchoAnalyzer(), $notifier, $this->clock))->run();

        return [$result, $notifier, $state];
    }

    public function nextWindow(): void
    {
        $this->clock->advance('+24 hours');
    }

    /** @return list<string> */
    public static function texts(Notifier|FakeNotifier $n): array
    {
        return array_map(
            static fn (array $s): string => $s['message']->text . "\n" . implode("\n", array_column($s['message']->sections, 'text')),
            $n instanceof FakeNotifier ? $n->sent : [],
        );
    }
}
