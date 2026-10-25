<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Issues\IssueRecord;
use Watcher\Level;
use Watcher\Metrics\MetricResult;
use Watcher\Observe\ObserveCounters;
use Watcher\Operate\JsonSummary;
use Watcher\Tests\Fakes\FakeIssueSource;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeMetricSource;
use Watcher\Tests\Fakes\SpyLogger;
use Watcher\Watcher;

/**
 * Principle I for spec 009: whatever a Sentry issue or a Prometheus label holds, only counts and allowlisted fields leave. A seeded
 * e-mail, a secret and a personal id placed in an issue title, an issue culprit and a label value appear in no sink output, no model
 * input, no summary, no host log and no notice. (A masked title and the metrics line are allowlisted digest text, not sink text.)
 */
final class MetricsSentryPrivacyTest extends TestCase
{
    private const CANARY_SECRET = 'CANARYSECRET-7Qk2xVd9Lm4Zp8Tn';
    private const CANARY_EMAIL = 'canary.person@canary-corp.example';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/m009privacy-' . bin2hex(random_bytes(6));
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

    /** @return array<string, array{bool}> */
    public static function modes(): array
    {
        return ['text off' => [false], 'text on' => [true]];
    }

    #[DataProvider('modes')]
    public function testNoSeededValueReachesAnySinkModelInputOrLog(bool $includeText): void
    {
        /** @var list<string> $seeded */
        $seeded = require __DIR__ . '/../Fixtures/privacy/seeded-values-009.php';
        $needles = [...$seeded, self::CANARY_SECRET, self::CANARY_EMAIL];
        $h = new Harness([
            'schedule' => ['run_at' => '06:00'],
            'metrics' => [
                'source' => ['base_url' => 'https://prom.example.com'],
                'label_allowlist' => ['customer', 'email'],
                'queries' => ['latency' => ['query' => 'up', 'aggregate' => 'max', 'threshold' => ['relative' => 0.5, 'floor' => 0.1]]],
            ],
            'sentry' => ['organization' => 'acme', 'project' => 'orders', 'auth_token' => 'tok-sentry-1234'],
            'observe' => [
                'log' => ['enabled' => true, 'include_text' => $includeText],
                'file' => ['enabled' => true, 'path' => $this->dir . '/events.jsonl', 'include_text' => $includeText],
                'prometheus' => ['enabled' => true, 'dir' => $this->dir],
            ],
        ]);
        $h->hostLog = $hostLog = new SpyLogger();
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $config = WatchConfig::fromArray($h->config());
        $counters = new ObserveCounters($h->state, 'svc', 2592000);
        $sinks = Factory::observe($config, $h->clock, $hostLog, $counters, logStream: $stream);

        $title = 'Cannot charge ' . self::CANARY_EMAIL . ' / anna.kowalski@example.org card 4111 1111 1111 1111 key ' . self::CANARY_SECRET . ' token sk_demo_51HxQ2eKzP9vWb3nTq7LmZ4Yc';
        $culprit = 'App\\Pay::charge for customer-4711 ' . self::CANARY_EMAIL;
        $issues = new FakeIssueSource([new IssueRecord('2001', $title, $culprit, Level::Error, new DateTimeImmutable('2026-10-07T10:00:00Z'), new DateTimeImmutable('2026-10-08T05:00:00Z'), 40)]);
        $n = 0;
        $metrics = new FakeMetricSource(static function () use (&$n): MetricResult {
            return new MetricResult([++$n % 2 === 1 ? 1.2 : 0.4], 1, ['customer-4711', 'anna.kowalski@example.org', self::CANARY_EMAIL, self::CANARY_SECRET]);
        });
        $events = Harness::events(Level::Error, 'Alpha service exploded badly', 5, 'AlphaException');
        $make = static fn () => new Watcher($config, new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $h->analyzer, $h->notifier, $h->clock, $hostLog, null, null, sinks: $sinks, counters: $counters, metrics: $metrics, issues: $issues);
        $make()->run();
        $h->nextWindow();
        $result = $make()->run();

        self::assertNotNull($result->summaryData);
        self::assertNotSame([], $metrics->calls);
        self::assertSame(1, $issues->calls > 0 ? 1 : 0);
        rewind($stream);
        $outputs = [
            'log sink' => (string) stream_get_contents($stream),
            'file sink' => (string) file_get_contents($this->dir . '/events.jsonl'),
            'prometheus' => (string) file_get_contents($this->dir . '/watcher_svc.prom'),
            'summary' => (new JsonSummary())->render($result->summaryData),
            'host log' => (string) json_encode($hostLog->records),
            'model input' => (string) json_encode(array_map(static fn ($r): mixed => $r->groups, $h->analyzer->allRequests()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'notifier' => (string) json_encode(array_map(static fn (array $s) => $s['message'], $h->notifier->sent), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        foreach (['log sink', 'file sink', 'prometheus', 'summary', 'model input', 'notifier'] as $name) {
            self::assertNotSame('', $outputs[$name], "$name produced no output");
        }
        self::assertStringContainsString('sentry:2001', $outputs['model input']); // the issue did travel, as masked text
        self::assertStringContainsString('Cannot charge', $outputs['model input']);
        foreach ($outputs as $where => $text) {
            foreach ([$text, (string) json_encode($text)] as $haystack) {
                foreach ($needles as $needle) {
                    self::assertStringNotContainsString($needle, $haystack, "$needle leaked into $where");
                }
            }
        }
    }
}
