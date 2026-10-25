<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Issues\IssueRecord;
use Watcher\Issues\IssueSource;
use Watcher\Level;
use Watcher\Metrics\MetricResult;
use Watcher\Metrics\MetricSource;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeIssueSource;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeMetricSource;
use Watcher\Watcher;

/** Spec 009 end to end with fakes: the metrics line, metric anomalies, Sentry groups and the unavailable notes. */
final class MetricsSentryTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function sections(): array
    {
        return [
            'metrics' => [
                'source' => ['base_url' => 'https://prom.invalid'],
                'label_allowlist' => ['customer'],
                'queries' => ['p95_latency' => ['query' => 'histogram_quantile(0.95, x{service="{service}"})', 'aggregate' => 'max', 'unit' => 's', 'threshold' => ['relative' => 0.5, 'floor' => 0.1]]],
            ],
            'sentry' => ['organization' => 'acme', 'project' => 'orders', 'auth_token' => 'tok-sentry-1234'],
        ];
    }

    private static function make(Harness $h, FakeLogSource $log, ?MetricSource $metrics = null, ?IssueSource $issues = null): Watcher
    {
        return new Watcher(WatchConfig::fromArray($h->config()), $log, $h->state, $h->analyzer, $h->notifier, $h->clock, $h->hostLog, null, null, metrics: $metrics, issues: $issues);
    }

    private static function errors(int $n): FakeLogSource
    {
        $events = Harness::events(Level::Error, 'Alpha service exploded badly', $n, 'AlphaException');

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    private static function quiet(): FakeLogSource
    {
        $events = Harness::events(Level::Info, 'heartbeat ok', 2);

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /** current 1.2 s, baseline 0.4 s (the reader asks for the current value first, then the baseline). */
    private static function tripled(): FakeMetricSource
    {
        $n = 0;

        return new FakeMetricSource(static function () use (&$n): MetricResult {
            return new MetricResult([++$n % 2 === 1 ? 1.2 : 0.4], 1);
        });
    }

    private static function record(string $id, string $title, string $culprit = 'App\\Pay::charge', int $count = 40): IssueRecord
    {
        return new IssueRecord($id, $title, $culprit, Level::Error, new DateTimeImmutable('2026-10-07T10:00:00Z'), new DateTimeImmutable('2026-10-08T05:00:00Z'), $count);
    }

    public function testTheMetricsLineAppearsNextToALogFindingAndTheBaselineRunReadsNothing(): void
    {
        $h = new Harness(self::sections());
        $metrics = self::tripled();
        self::assertSame(RunOutcome::Baselined, self::make($h, self::errors(5), $metrics)->run()->outcome);
        self::assertSame([], $metrics->calls);
        $h->nextWindow();

        $result = self::make($h, self::errors(20), $metrics)->run();
        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertSame(['read' => 1, 'failed' => 0, 'anomalies' => 0], $result->summaryData?->stats?->metrics());
        self::assertNull($result->summaryData->stats->sentry());

        self::assertCount(2, $metrics->calls); // current and baseline, once per metric
        $text = implode("\n", $h->sentTexts());
        self::assertStringContainsString('Metrics: p95 latency 1.2 s (was 0.4 s, +200%)', $text);
    }

    public function testAnUnreadableBackendCostsOneNoteAndNothingElse(): void
    {
        $h = new Harness(self::sections());
        self::make($h, self::errors(5), FakeMetricSource::failing('timeout'))->run();
        $h->nextWindow();
        self::assertSame(RunOutcome::Reported, self::make($h, self::errors(20), FakeMetricSource::failing('timeout'))->run()->outcome);

        $text = implode("\n", $h->sentTexts());
        self::assertStringContainsString('Metrics unavailable (timeout)', $text);
        self::assertStringNotContainsString('Metrics:', $text);
    }

    public function testASilentMetricRegressionBecomesOneAnomalyCandidate(): void
    {
        $h = new Harness(self::sections());
        self::make($h, self::quiet(), self::tripled())->run();
        $h->nextWindow();

        $result = self::make($h, self::quiet(), self::tripled())->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $seen = array_map(static fn ($g) => $g->template, [...($h->analyzer->triageRequests[0]->groups ?? [])]);
        // the Redactor's own Normalizer masks the digits of the declared name (p95 -> p<N>): the model sees the shape, never more than the rules allow
        self::assertContains('metric anomaly: p<N>_latency', $seen);
    }

    public function testTheModelReceivesTheNumbersOfAnAnomalyAndTheNextRequestSaysSo(): void
    {
        $h = new Harness(self::sections());
        self::make($h, self::quiet(), self::tripled())->run();
        $h->nextWindow();
        self::make($h, self::quiet(), self::tripled())->run();

        $req = $h->analyzer->triageRequests[0];
        self::assertNotNull($req->extras);
        $json = \Watcher\Analyze\CompactPayload::encode($req->groups, [], $req->extras);
        self::assertStringContainsString('"m":{"n":["p95_latency"],"v":[[1.2,0.4]],"u":["s"],"r":[2]}', $json);
        self::assertStringContainsString('"mi":0', $json);
        $analysis = $h->analyzer->requests[0];
        self::assertNotNull($analysis->extras);
    }

    /** Review H1: a baseline so small that the ratio overflows must not cost the run. */
    public function testADegenerateRatioDoesNotFailTheRunAndIsShownAsNotComputable(): void
    {
        foreach ([[1.0e10, 1.0e-320], [1.7e308, 1.0e-300], [1.0e300, 1.0e-10]] as [$cur, $base]) {
            $h = new Harness(self::sections());
            $n = 0;
            $metrics = new FakeMetricSource(static function () use (&$n, $cur, $base): MetricResult {
                return new MetricResult([++$n % 2 === 1 ? $cur : $base], 1);
            });
            self::make($h, self::errors(5), $metrics)->run();
            $h->nextWindow();

            $result = self::make($h, self::errors(20), $metrics)->run();

            self::assertSame(RunOutcome::Reported, $result->outcome, "$cur / $base");
            self::assertStringContainsString('change not computable', implode("\n", $h->sentTexts()));
        }
    }

    public function testAnErrorGroupExplainsTheMetricMoveSoThereIsNoAnomaly(): void
    {
        $h = new Harness(self::sections());
        self::make($h, self::errors(5), self::tripled())->run();
        $h->nextWindow();
        self::make($h, self::errors(20), self::tripled())->run();

        foreach ($h->analyzer->triageRequests as $req) {
            foreach ($req->groups as $g) {
                self::assertStringNotContainsString('metric anomaly', (string) $g->template);
            }
        }
    }

    public function testNothingConfiguredCallsNoSource(): void
    {
        $h = new Harness();
        $metrics = self::tripled();
        $issues = new FakeIssueSource([self::record('1', 'Boom')]);
        self::make($h, self::errors(5), $metrics, $issues)->run();
        $h->nextWindow();
        self::make($h, self::errors(20), $metrics, $issues)->run();

        self::assertSame([], $metrics->calls);
        self::assertSame(0, $issues->calls);
        self::assertStringNotContainsString('Sentry', implode("\n", $h->sentTexts()));
    }

    public function testASentryIssueIsItsOwnEntryWithAnIssueLink(): void
    {
        $h = new Harness(self::sections());
        $issues = new FakeIssueSource([self::record('2001', 'PaymentGatewayException: card declined')]);
        self::make($h, self::errors(5), null, $issues)->run();
        $h->nextWindow();

        $result = self::make($h, self::errors(20), null, $issues)->run();
        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertSame(['read' => 1, 'dropped' => 0, 'merged' => 1, 'failed' => 0], $result->summaryData?->stats?->sentry());

        $fps = [];
        foreach ($h->analyzer->triageRequests as $req) {
            foreach ($req->groups as $g) {
                $fps[] = $g->fingerprint;
            }
        }
        self::assertContains('sentry:2001', $fps);
        self::assertCount(2, array_unique($fps)); // the log group and the Sentry group stay two entries
        self::assertStringContainsString('<https://sentry.io/organizations/acme/issues/2001/|Sentry>', implode("\n", $h->sentTexts()));
    }

    public function testAFailingSentryLeavesTheLogFindingsIdenticalAndNamesTheToken(): void
    {
        $plain = new Harness(self::sections());
        self::make($plain, self::errors(5))->run();
        $plain->nextWindow();
        self::make($plain, self::errors(20))->run();

        foreach (['window_too_old', 'unauthorized', 'timeout'] as $token) {
            $h = new Harness(self::sections());
            self::make($h, self::errors(5), null, FakeIssueSource::failing($token))->run();
            $h->nextWindow();
            self::assertSame(RunOutcome::Reported, self::make($h, self::errors(20), null, FakeIssueSource::failing($token))->run()->outcome);

            $text = implode("\n", $h->sentTexts());
            self::assertStringContainsString("Sentry unavailable ({$token})", $text);
            self::assertSame($plain->analyzer->requests[0]->groups[0]->fingerprint, $h->analyzer->requests[0]->groups[0]->fingerprint);
        }
    }

    public function testDroppedIssuesAreCounted(): void
    {
        $h = new Harness(self::sections());
        $issues = new class () implements IssueSource {
            public function issues(\Watcher\Schedule\Window $window, int $limit): array
            {
                return [];
            }

            public function dropped(): int
            {
                return 2;
            }
        };
        self::make($h, self::errors(5), null, $issues)->run();
        $h->nextWindow();
        self::make($h, self::errors(20), null, $issues)->run();

        self::assertStringContainsString('2 Sentry issues dropped', implode("\n", $h->sentTexts()));
    }
}
