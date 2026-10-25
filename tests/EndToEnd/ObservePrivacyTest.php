<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\Usage;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Observe\ObserveCounters;
use Watcher\Operate\JsonSummary;
use Watcher\Source\LineParser;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\SpyLogger;
use Watcher\Watcher;

/** Principle I for the sinks (spec 013 US5): whatever a log line holds, no sink output, summary, log or failure message carries it. */
final class ObservePrivacyTest extends TestCase
{
    public const CANARY_SECRET = 'CANARYSECRET-7Qk2xVd9Lm4Zp8Tn';
    public const CANARY_EMAIL = 'canary.person@canary-corp.example';
    private const TOKEN = 'tok-observe-CANARYTOKEN1';
    private const URL = 'https://ingest.example.com/in/CANARYPATH-5d2b';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/obsprivacy-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                unlink($this->dir . '/' . $f);
            }
        }
        rmdir($this->dir);
    }

    /** @return array<string, array{bool, int}> include_text, http status the receiver answers */
    public static function modes(): array
    {
        return [
            'text off, receiver ok' => [false, 200],
            'text on, receiver ok' => [true, 200],
            'text off, receiver 500' => [false, 500],
            'text on, receiver 500' => [true, 500],
        ];
    }

    /** @return list<\Watcher\Source\LogEvent> the privacy corpus plus a line that holds a canary secret and a canary e-mail */
    private static function events(): array
    {
        $parser = new LineParser();
        $lines = file(__DIR__ . '/../Fixtures/privacy/corpus.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines[] = '[2026-10-07T21:30:01+00:00] production.ERROR: [APP]: Canary job failed for ' . self::CANARY_EMAIL . ' using key ' . self::CANARY_SECRET . ' {"api_key":"' . self::CANARY_SECRET . '","email":"' . self::CANARY_EMAIL . '","exception":"CanaryException: bad ' . self::CANARY_SECRET . ' ' . self::CANARY_EMAIL . '"} []';
        $events = [];
        foreach ($lines as $line) {
            $events[] = $parser->parse($line);
        }

        return $events;
    }

    #[DataProvider('modes')]
    public function testNoSeededValueOrCanaryReachesAnySinkOrFailureMessage(bool $includeText, int $receiver): void
    {
        /** @var list<string> $seeded */
        $seeded = require __DIR__ . '/../Fixtures/privacy/seeded-values.php';
        $needles = [...$seeded, self::CANARY_SECRET, self::CANARY_EMAIL, 'CANARYTOKEN', 'CANARYPATH'];
        $events = self::events();
        $h = new Harness([
            'schedule' => ['run_at' => '06:00'],
            'observe' => [
                'log' => ['enabled' => true, 'include_text' => $includeText],
                'file' => ['enabled' => true, 'path' => $this->dir . '/events.jsonl', 'include_text' => $includeText],
                'http' => ['enabled' => true, 'url' => self::URL, 'auth_token' => self::TOKEN, 'include_text' => $includeText],
                'prometheus' => ['enabled' => true, 'dir' => $this->dir],
            ],
        ]);
        $h->hostLog = $hostLog = new SpyLogger();
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response($receiver, [], 'echo ' . self::TOKEN . ' ' . self::URL), new Response($receiver, [], 'x'), new Response($receiver), new Response($receiver)]));
        $stack->push(Middleware::history($history));
        $config = WatchConfig::fromArray($h->config());
        $counters = new ObserveCounters($h->state, 'svc', 2592000);
        $sinks = Factory::observe($config, $h->clock, $hostLog, $counters, new Client(['handler' => $stack]), logStream: $stream);
        $analyzer = new CanaryInventingAnalyzer(); // a model that repeats every template it was shown into its findings
        $make = static fn () => new Watcher($config, new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $h->notifier, $h->clock, $hostLog, null, null, sinks: $sinks, counters: $counters);
        $make()->run();
        $h->nextWindow();
        $result = $make()->run();

        self::assertNotNull($result->summaryData);
        rewind($stream);
        $outputs = [
            'log sink' => (string) stream_get_contents($stream),
            'file sink' => (string) file_get_contents($this->dir . '/events.jsonl'),
            'http body' => implode("\n", array_map(static fn (array $t): string => (string) $t['request']->getBody(), array_filter((array) $history, static fn (array $t): bool => str_contains((string) $t['request']->getBody(), 'watcher.event.v1')))),
            'prometheus' => (string) file_get_contents($this->dir . '/watcher_svc.prom'),
            'summary' => (new JsonSummary())->render($result->summaryData),
            'host log' => (string) json_encode($hostLog->records),
            'notifier' => (string) json_encode(array_map(static fn (array $s) => $s['message'], $h->notifier->sent), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        foreach (['log sink', 'file sink', 'http body', 'prometheus', 'summary'] as $sink) {
            self::assertNotSame('', $outputs[$sink], "$sink produced no output");
        }
        // The test has teeth: with text on the leaky model's words are in the outputs, with text off they are not.
        self::assertSame($includeText, str_contains($outputs['log sink'], '"title":"Problem '));
        self::assertSame($includeText, str_contains($outputs['file sink'], '"title":"Problem '));
        if ($receiver !== 200) {
            self::assertSame(['http' => 1], $result->summaryData->sinkFailures());
        }
        foreach ($outputs as $where => $text) {
            foreach ([$text, (string) json_encode($text)] as $haystack) { // raw and \u-escaped
                foreach ($needles as $needle) {
                    self::assertStringNotContainsString($needle, $haystack, "$needle leaked into $where");
                }
            }
        }
    }

    public function testWithTextOffEveryEventValidatesAndCarriesNoFindingText(): void
    {
        $events = self::events();
        $h = new Harness(['schedule' => ['run_at' => '06:00'], 'observe' => ['file' => ['enabled' => true, 'path' => $this->dir . '/events.jsonl']]]);
        $config = WatchConfig::fromArray($h->config());
        $sinks = Factory::observe($config, $h->clock, null, new ObserveCounters($h->state, 'svc', 2592000));
        $analyzer = new CanaryInventingAnalyzer();
        $make = static fn () => new Watcher($config, new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $h->notifier, $h->clock, null, null, null, sinks: $sinks);
        $make()->run();
        $h->nextWindow();
        $make()->run();

        $schema = json_decode((string) file_get_contents(__DIR__ . '/../../docs/schema/watcher.event.v1.schema.json'), false, 512, JSON_THROW_ON_ERROR);
        $lines = file($this->dir . '/events.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertGreaterThan(2, count($lines));
        $types = [];
        foreach ($lines as $line) {
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue((new Validator())->validate(json_decode($line, false, 512, JSON_THROW_ON_ERROR), $schema)->isValid(), 'schema-invalid event: ' . $event['event_type']);
            foreach (['title', 'evidence', 'hypothesis', 'next_step', 'check', 'related', 'error_type'] as $text) {
                self::assertArrayNotHasKey($text, $event);
            }
            $types[$event['event_type']] = true;
        }
        self::assertArrayHasKey('finding', $types);
        self::assertArrayHasKey('group', $types);
    }
}

/** Repeats the (redacted) template it was shown into title and evidence, and invents a canary e-mail and secret: the output scrub must catch what a model makes up. */
final class CanaryInventingAnalyzer implements Analyzer
{
    public function triage(TriageRequest $req): TriageResult
    {
        $verdicts = [];
        foreach ($req->groups as $g) {
            $verdicts[$g->fingerprint] = 'interesting';
        }

        return new TriageResult($verdicts, new Usage($req->model, 100, 10));
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        $findings = array_map(
            static fn ($g): Finding => new Finding([$g->fingerprint], FindingKind::Bug, Severity::Error, 'Problem ' . substr((string) $g->template, 0, 60), 'saw ' . $g->template . ' contact ' . ObservePrivacyTest::CANARY_EMAIL . ' key ' . ObservePrivacyTest::CANARY_SECRET, 'cause ' . $g->errorType, 'inspect ' . $g->template, 0.9),
            $req->groups,
        );

        return new AnalysisResult($findings, new Usage($req->model, 100, 10));
    }
}
