<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\Usage;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Source\LogEvent;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Watcher;

/** Shared wiring for the end-to-end suites: fakes only, no network. */
final class Harness
{
    public readonly FrozenClock $clock;
    public StateStore $state;
    public readonly FakeNotifier $notifier;
    public readonly EchoAnalyzer $analyzer;

    /** @param array<string, mixed> $configPatch */
    public function __construct(private readonly array $configPatch = [])
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00'));
        $this->state = new InMemoryStateStore($this->clock);
        $this->notifier = new FakeNotifier();
        $this->analyzer = new EchoAnalyzer();
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return array_replace_recursive([
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => [
                'driver' => 'loki',
                'loki' => ['url' => 'http://loki.invalid'],
                'stream_tags' => ['app' => 'svc', 'env' => 'stg'],
                'anomaly_cap' => 100,
            ],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm-t', 'analyze' => 'm-a', 'escalate' => 'm-e'],
                'prices' => ['m-t' => [1, 5, 0.1, 1.25], 'm-a' => [3, 15, 0.3, 3.75], 'm-e' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/nonexistent']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
        ], $this->configPatch);
    }

    public function watcher(FakeLogSource $source): Watcher
    {
        return new Watcher(WatchConfig::fromArray($this->config()), $source, $this->state, $this->analyzer, $this->notifier, $this->clock);
    }

    /** Advance one full window (the default PT24H). */
    public function nextWindow(): void
    {
        $this->clock->advance('+24 hours');
    }

    public function windowEnd(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function lockKey(): string
    {
        return \Watcher\State\Keys::lock('svc', Watcher::windowKey($this->windowEnd()));
    }

    /** @return list<string> all message text, sections included, per sent message */
    public function sentTexts(): array
    {
        return array_map(
            static fn (array $s): string => $s['message']->text . "\n" . implode("\n", array_column($s['message']->sections, 'text')),
            $this->notifier->sent,
        );
    }

    /**
     * @param list<LogEvent> $events
     * @return array<string, int>
     */
    public static function totalsOf(array $events): array
    {
        $t = [];
        foreach ($events as $e) {
            $k = strtolower($e->level->name);
            $t[$k] = ($t[$k] ?? 0) + 1;
        }

        return $t;
    }

    /** @return list<LogEvent> */
    public static function events(Level $level, string $message, int $n, ?string $type = null): array
    {
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $out[] = new LogEvent(new DateTimeImmutable('2026-10-07T12:00:00', new DateTimeZone('UTC')), $level, $message, $type, [], [], []);
        }

        return $out;
    }
}

/** Like FakeAnalyzer, but answers with one finding per fingerprint it receives (they are not known in advance). */
final class EchoAnalyzer implements Analyzer
{
    /** @var list<AnalysisRequest> */
    public array $requests = [];

    public function triage(TriageRequest $req): TriageResult
    {
        throw new \LogicException('triage is not used before US3');
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $this->requests[] = $req;
        $findings = array_map(
            static fn ($g): Finding => new Finding([$g->fingerprint], FindingKind::Bug, Severity::Error, 'Problem ' . $g->fingerprint, 'seen repeatedly', 'a dependency may be failing', 'inspect the dependency', 0.9),
            $req->groups,
        );

        return new AnalysisResult($findings, new Usage($req->model, 1200, 300, 0, 0, 0.01));
    }
}
