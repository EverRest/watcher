<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Budget;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\PayloadExtras;
use Watcher\Level;
use Watcher\Metrics\MetricReading;
use Watcher\Metrics\MetricsContext;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;

/** Spec 009 T011: the `m` section is built from measured numbers and declared names, bounded, and absent when there is nothing. */
final class PayloadExtrasTest extends TestCase
{
    private function reading(string $name, ?float $cur, ?float $base, string $state = 'ok', string $unit = ''): MetricReading
    {
        return new MetricReading($name, $unit, $cur, $base, $state, 1, ['CANARY-label']);
    }

    private function group(string $fp, ?string $tpl = 'tpl'): RedactedGroup
    {
        $t = new DateTimeImmutable('2026-10-23T01:00:00Z');

        return new RedactedGroup($fp, $tpl, Level::Warning, null, [], 5, null, GroupStatusLabel::Unchanged, [], $t, $t);
    }

    public function testNothingMeasuredMeansNoSection(): void
    {
        self::assertNull(PayloadExtras::fromContext(new MetricsContext([])));
        self::assertNull(PayloadExtras::fromContext(new MetricsContext([$this->reading('a', null, null, 'no_data')])));
        self::assertNull(PayloadExtras::fromContext(new MetricsContext([], true, 'timeout')));
    }

    public function testNamesPairsUnitsAndChangeAppearOnceAndAnAnomalyMetricComesFirst(): void
    {
        $ctx = new MetricsContext([$this->reading('big_move', 10.0, 1.0), $this->reading('p95_latency', 1.234567, 0.4, 'ok', 's'), $this->reading('fresh', 3.0, null, 'no_baseline')]);
        $m = PayloadExtras::fromContext($ctx, ['metric:abc' => 'p95_latency']);

        self::assertNotNull($m);
        $s = $m->section();
        self::assertSame(['p95_latency', 'big_move', 'fresh'], $s['n']);
        self::assertSame([[1.23, 0.4], [10.0, 1.0], [3.0, null]], $s['v']);
        self::assertSame(['s', '', ''], $s['u']);
        self::assertSame([2.09, 9.0, null], $s['r']);
        self::assertSame(0, $m->indexFor('metric:abc'));
    }

    public function testAtMostThreeMetricsAndLabelValuesNeverAppear(): void
    {
        $ctx = new MetricsContext(array_map(fn (int $i): MetricReading => $this->reading("m$i", (float) ($i + 2), 1.0), range(1, 6)));
        $m = PayloadExtras::fromContext($ctx);

        self::assertNotNull($m);
        self::assertCount(3, $m->names);
        self::assertStringNotContainsString('CANARY', (string) json_encode($m->section()));
    }

    public function testEncodeAddsMAndMiOnlyWhenGivenAndIsOtherwiseUnchanged(): void
    {
        $g = [$this->group('metric:abc', 'metric anomaly: x'), $this->group('ffff')];
        $plain = CompactPayload::encode($g);
        $m = PayloadExtras::fromContext(new MetricsContext([$this->reading('x', 2.0, 1.0)]), ['metric:abc' => 'x']);

        self::assertStringNotContainsString('"m"', $plain);
        self::assertStringNotContainsString('"mi"', $plain);
        $with = json_decode(CompactPayload::encode($g, [], $m), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals(['n' => ['x'], 'v' => [[2.0, 1.0]], 'u' => [''], 'r' => [1.0]], $with['m']);
        self::assertSame(0, $with['g'][0]['mi']);
        self::assertArrayNotHasKey('mi', $with['g'][1]);
        $without = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        unset($with['m'], $with['g'][0]['mi']);
        self::assertSame($without, $with);
    }

    public function testTriageSeesTheNumbersOnlyForAnAnomalyBatch(): void
    {
        $m = PayloadExtras::fromContext(new MetricsContext([$this->reading('x', 2.0, 1.0)]), ['metric:abc' => 'x']);

        self::assertNotNull($m);
        self::assertNull($m->forTriage([$this->group('ffff')]));
        self::assertSame($m, $m->forTriage([$this->group('ffff'), $this->group('metric:abc')]));
    }

    public function testTheSystemPromptExplainsTheSectionOnlyWhenItIsSent(): void
    {
        $base = Budget::systemPrompt('analyze', 'ctx');

        self::assertSame($base, Budget::systemPrompt('analyze', 'ctx', true, false));
        self::assertStringNotContainsString('Measured metrics', $base);
        self::assertStringContainsString('`m` section', Budget::systemPrompt('analyze', 'ctx', true, true));
        self::assertGreaterThan(Budget::fixedTokens('triage', 'ctx'), Budget::fixedTokens('triage', 'ctx', true, true));
    }

    public function testThreeMetricsCostAtMostSixtyEstimatedInputTokens(): void
    {
        $g = [$this->group('ffff')];
        $m = PayloadExtras::fromContext(new MetricsContext([$this->reading('error_rate', 0.0123, 0.004, 'ok', '%'), $this->reading('p95_latency', 1.23, 0.41, 'ok', 's'), $this->reading('queue_depth', 1234.0, 410.0)]));

        $cost = Budget::estimateTokens(CompactPayload::encode($g, [], $m)) - Budget::estimateTokens(CompactPayload::encode($g));
        self::assertLessThanOrEqual(60, $cost);
    }

    public function testFitMetricsDropsLastMetricsThenTheWholeSectionNeverTheGroups(): void
    {
        $g = [$this->group('ffff')];
        $m = PayloadExtras::fromContext(new MetricsContext([$this->reading('a', 2.0, 1.0), $this->reading('b', 3.0, 1.0), $this->reading('c', 4.0, 1.0)]));
        self::assertNotNull($m);
        $full = Budget::fixedTokens('analyze', 'ctx', true, true) + Budget::estimateTokens(CompactPayload::encode($g, [], $m));
        $two = Budget::fixedTokens('analyze', 'ctx', true, true) + Budget::estimateTokens(CompactPayload::encode($g, [], $m->limit(2)));

        $all = Budget::fitMetrics($g, $m, $full, 'ctx', true);
        $some = Budget::fitMetrics($g, $m, $full - 1, 'ctx', true);
        self::assertNotNull($all);
        self::assertNotNull($some);
        self::assertCount(3, $all->names);
        self::assertCount(2, $some->names);
        self::assertNull(Budget::fitMetrics($g, $m, 10, 'ctx', true));
        self::assertLessThan($full, $two);
        self::assertNull(Budget::fitMetrics($g, null, 100000, 'ctx', true));
    }
}
