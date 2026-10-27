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
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Source\LogEvent;
use Watcher\State\StateStore;
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
    public ?\Psr\Log\LoggerInterface $hostLog = null;

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
        // The gate runs only inside the run_at hour, so by default the schedule follows the clock (UTC);
        // tests of the gate itself pass schedule.run_at explicitly.
        $config = array_replace_recursive([
            'service' => 'svc',
            'enabled' => true,
            'schedule' => ['run_at' => $this->clock->now()->format('H:i')],
            'source' => [
                'driver' => 'loki',
                'loki' => ['url' => 'https://loki.invalid'],
                'stream_tags' => ['app' => 'svc', 'env' => 'stg'],
                'anomaly_cap' => 100,
            ],
            'analysis' => [
                'api_keys' => ['gemini' => 'k'],
                'models' => ['triage' => 'm-t', 'analyze' => 'm-a', 'escalate' => 'm-e'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/nonexistent']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
        ], $this->configPatch);
        // A destination in the patch replaces the default wholesale (a Telegram entry must not inherit the Slack webhook).
        foreach (['digest', 'notice'] as $kind) {
            if (is_array($this->configPatch['notify'][$kind] ?? null)) {
                $config['notify'][$kind] = $this->configPatch['notify'][$kind];
            }
        }

        return $config;
    }

    public function watcher(\Watcher\Source\LogSource $source, ?\Watcher\Operate\RunOptions $options = null, mixed $out = null, ?\Watcher\Source\KubernetesEventsSource $events = null): Watcher
    {
        return new Watcher(WatchConfig::fromArray($this->config()), $source, $this->state, $this->analyzer, $this->notifier, $this->clock, $this->hostLog, $options, $out, null, $events);
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

/** Like FakeAnalyzer, but answers per fingerprint it receives (they are not known in advance). */
final class EchoAnalyzer implements Analyzer
{
    /** @var list<AnalysisRequest> analyze requests only, as before triage existed */
    public array $requests = [];

    /** @var list<TriageRequest> */
    public array $triageRequests = [];

    public ?\Watcher\Analyze\AnalyzerFailed $failure = null;
    /** Fails analyze() only, so triage has already completed and been paid for. */
    public ?\Watcher\Analyze\AnalyzerFailed $analyzeFailure = null;
    public string $verdict = 'interesting';
    public float $confidence = 0.9;

    /** @return list<TriageRequest|AnalysisRequest> every request the model would have seen */
    public function allRequests(): array
    {
        return [...$this->triageRequests, ...$this->requests];
    }

    public function triage(TriageRequest $req): TriageResult
    {
        $this->triageRequests[] = $req;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $verdicts = [];
        foreach ($req->groups as $g) {
            $verdicts[$g->fingerprint] = $this->verdict;
        }

        return new TriageResult($verdicts);
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $this->requests[] = $req;
        if ($this->analyzeFailure !== null) {
            throw $this->analyzeFailure;
        }
        // Escalation (the "m-e" model) is the confident second opinion.
        $confidence = $req->model === 'm-e' ? 0.95 : $this->confidence;
        // Like a model told to name a dependency that shows errors in the same window.
        $evidence = '';
        foreach ($req->dependencyContext as $dep) {
            $evidence .= '; dependency ' . $dep->name . ' shows ' . $dep->errors() . ' errors';
        }
        $findings = array_map(
            static fn ($g): Finding => new Finding([$g->fingerprint], FindingKind::Bug, Severity::Error, 'Problem ' . $g->fingerprint, 'seen repeatedly' . $evidence, 'a dependency may be failing', 'inspect the dependency', $confidence),
            $req->groups,
        );

        return new AnalysisResult($findings);
    }
}
