<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Metrics\MetricDefinition;
use Watcher\Metrics\MetricReading;
use Watcher\Metrics\MetricResult;
use Watcher\Metrics\MetricsReader;
use Watcher\Metrics\QueryTemplate;
use Watcher\Schedule\Window;
use Watcher\Tests\Fakes\FakeMetricSource;

final class MetricsReaderTest extends TestCase
{
    private function window(): Window
    {
        return Window::endingAt(new DateTimeImmutable('2026-10-23T00:00:00Z'));
    }

    private function def(string $name = 'p95', ?string $aggregate = null, string $mode = 'instant', string $unit = 'ms'): MetricDefinition
    {
        return new MetricDefinition($name, QueryTemplate::compile($name, 'm{s="{service}"}', 'svc', []), $aggregate, null, $unit, $mode);
    }

    /** Source answering `current` at window end and `baseline` at baseline end. */
    private function source(MetricResult $current, MetricResult $baseline): FakeMetricSource
    {
        $end = $this->window()->end->getTimestamp();

        return new FakeMetricSource(static fn (string $q, DateTimeImmutable $at): MetricResult => $at->getTimestamp() === $end ? $current : $baseline);
    }

    public function testOneSeriesNeedsNoAggregateAndReportsChange(): void
    {
        $src = $this->source(new MetricResult([820.0], 1), new MetricResult([310.0], 1));

        $ctx = (new MetricsReader($src, [$this->def()]))->read($this->window());

        $r = $ctx->readings[0];
        self::assertSame('ok', $r->state);
        self::assertSame(820.0, $r->current);
        self::assertSame(310.0, $r->baseline);
        self::assertEqualsWithDelta(1.645, (float) $r->relativeChange(), 0.001);
        self::assertSame(510.0, $r->absoluteChange());
        self::assertFalse($ctx->unavailable);
    }

    /** @return array<string, array{string, float}> */
    public static function aggregates(): array
    {
        return ['sum' => ['sum', 6.0], 'max' => ['max', 3.0], 'avg' => ['avg', 2.0], 'last' => ['last', 3.0]];
    }

    #[DataProvider('aggregates')]
    public function testAggregates(string $aggregate, float $expected): void
    {
        $src = $this->source(new MetricResult([1.0, 2.0, 3.0], 3), new MetricResult([1.0], 1));

        $r = (new MetricsReader($src, [$this->def('x', $aggregate)]))->read($this->window())->readings[0];

        self::assertSame($expected, $r->current);
    }

    public function testManySeriesWithoutAggregateIsAmbiguousAndSkipped(): void
    {
        $src = $this->source(new MetricResult([1.0, 2.0], 2), new MetricResult([1.0], 1));

        $r = (new MetricsReader($src, [$this->def()]))->read($this->window())->readings[0];

        self::assertSame('ambiguous', $r->state);
        self::assertSame(2, $r->series);
        self::assertNull($r->relativeChange());
    }

    public function testEmptyIsNoDataNeverZero(): void
    {
        $src = $this->source(new MetricResult([], 0), new MetricResult([1.0], 1));

        $r = (new MetricsReader($src, [$this->def()]))->read($this->window())->readings[0];

        self::assertSame('no_data', $r->state);
        self::assertNull($r->current);
    }

    #[DataProvider('noBaselines')]
    public function testMissingOrNonPositiveBaselineKeepsCurrentWithoutPercent(MetricResult $baseline): void
    {
        $src = $this->source(new MetricResult([5.0], 1), $baseline);

        $r = (new MetricsReader($src, [$this->def()]))->read($this->window())->readings[0];

        self::assertSame('no_baseline', $r->state);
        self::assertSame(5.0, $r->current);
        self::assertNull($r->relativeChange());
    }

    /** @return array<string, array{MetricResult}> */
    public static function noBaselines(): array
    {
        return ['null' => [new MetricResult([], 0)], 'zero' => [new MetricResult([0.0], 1)], 'negative' => [new MetricResult([-1.0], 1)]];
    }

    public function testInstantModeMakesExactlyTwoCallsPerMetric(): void
    {
        $src = $this->source(new MetricResult([1.0], 1), new MetricResult([1.0], 1));

        (new MetricsReader($src, [$this->def('a'), $this->def('b')]))->read($this->window());

        self::assertCount(4, $src->calls);
        $w = $this->window();
        self::assertSame([$w->end->getTimestamp(), $w->start->getTimestamp()], [$src->calls[0][2]->getTimestamp(), $src->calls[1][2]->getTimestamp()]);
        self::assertSame('m{s="svc"}', $src->calls[0][1]);
    }

    public function testRangeModeMakesOneCallAndPartitionsByTimestamp(): void
    {
        $w = $this->window();
        $b = $w->start->getTimestamp(); // baseline_end
        $stamps = [$b - 7200, $b - 3600, $b, $b + 3600];
        $src = new FakeMetricSource(null, static fn (): MetricResult => new MetricResult([1.0, 3.0, 10.0, 20.0], 1, [], $stamps));

        $r = (new MetricsReader($src, [$this->def('x', 'max', 'range')], 500))->read($w)->readings[0];

        self::assertCount(1, $src->calls);
        self::assertSame('range', $src->calls[0][0]);
        self::assertSame(3.0, $r->baseline);
        self::assertSame(20.0, $r->current);
    }

    public function testRangeStepComesFromMaxPoints(): void
    {
        $seen = null;
        $src = new FakeMetricSource(null, static function ($q, $s, $e, int $step) use (&$seen): MetricResult {
            $seen = $step;

            return new MetricResult([], 0);
        });

        (new MetricsReader($src, [$this->def('x', null, 'range')], 500))->read($this->window());

        self::assertSame(346, $seen); // 48 h / 500
    }

    public function testRangeWithManySeriesIsAmbiguous(): void
    {
        $src = new FakeMetricSource(null, static fn (): MetricResult => new MetricResult([], 2));

        $r = (new MetricsReader($src, [$this->def('x', 'sum', 'range')]))->read($this->window())->readings[0];

        self::assertSame('ambiguous', $r->state);
    }

    public function testAThrowingSourceMakesTheContextUnavailableWithTheFixedToken(): void
    {
        $ctx = (new MetricsReader(FakeMetricSource::failing('timeout'), [$this->def()]))->read($this->window());

        self::assertTrue($ctx->unavailable);
        self::assertSame('timeout', $ctx->failure);
        self::assertSame([], $ctx->readings);
        self::assertSame('Metrics unavailable (timeout)', $ctx->line());
    }

    public function testAnUnexpectedExceptionNeverEscapes(): void
    {
        $src = new FakeMetricSource(static function (): MetricResult {
            throw new \RuntimeException('secret https://x');
        });

        $ctx = (new MetricsReader($src, [$this->def()]))->read($this->window());

        self::assertTrue($ctx->unavailable);
        self::assertSame('bad_response', $ctx->failure);
    }

    public function testAllowedLabelValuesAreMaskedAndCapped(): void
    {
        $src = $this->source(new MetricResult([1.0], 1, ['anna.kowalski@example.org', 'api'], []), new MetricResult([1.0], 1));

        $r = (new MetricsReader($src, [$this->def()]))->read($this->window())->readings[0];

        self::assertSame(['api'], array_values(array_filter($r->labels, static fn (string $l): bool => $l === 'api')));
        self::assertStringNotContainsString('anna', implode(' ', $r->labels));
    }

    public function testDigestLineAndPayloadPairs(): void
    {
        $src = new FakeMetricSource(function (string $q, DateTimeImmutable $at): MetricResult {
            $cur = $at->getTimestamp() === $this->window()->end->getTimestamp();

            return new MetricResult([$cur ? 0.004123 : 0.001], 1);
        });
        $nodata = new MetricReading('p95', 'ms', null, null, 'no_data', 0, []);
        $ctx = (new MetricsReader($src, [$this->def('error_rate', null, 'instant', '%')]))->read($this->window());

        self::assertSame('Metrics: error rate 0.00412% (was 0.001%, +312%)', $ctx->line());
        self::assertSame(['error_rate'], $ctx->names());
        self::assertSame([[0.00412, 0.001]], $ctx->pairs());
        self::assertSame([[null, null]], (new \Watcher\Metrics\MetricsContext([$nodata]))->pairs());
    }

    public function testLineForNoBaselineAmbiguousAndNoData(): void
    {
        $ctx = new \Watcher\Metrics\MetricsContext([
            new MetricReading('p95', 'ms', 820.0, null, 'no_baseline', 1, []),
            new MetricReading('rps', '', null, null, 'no_data', 0, []),
            new MetricReading('err', '', null, null, 'ambiguous', 3, []),
        ]);

        self::assertSame('Metrics: p95 820 ms (no baseline) · rps no data · err ambiguous (3 series)', $ctx->line());
    }

    public function testOverflowingSumIsNoDataNotInfinity(): void
    {
        $src = $this->source(new MetricResult([1.7e308, 1.7e308], 2), new MetricResult([1.0], 1));

        foreach (['sum', 'avg'] as $agg) {
            $r = (new MetricsReader($src, [$this->def('x', $agg)]))->read($this->window())->readings[0];
            self::assertSame('no_data', $r->state);
            self::assertNull($r->current);
        }
    }

    public function testLabelsPassTheRedactorAsWellAsTheNormalizer(): void
    {
        $src = $this->source(new MetricResult([1.0], 1, ['anna.kowalski@example.org', 'customer-4711', 'api'], []), new MetricResult([1.0], 1));
        $redactor = new \Watcher\Privacy\Redactor(new \Watcher\Reduce\Normalizer());

        $labels = (new MetricsReader($src, [$this->def()], 500, new \Watcher\Reduce\Normalizer(), $redactor))->read($this->window())->readings[0]->labels;

        self::assertSame(['api'], $labels);
    }

    public function testALabelTheRedactorChangesIsDropped(): void
    {
        $src = $this->source(new MetricResult([1.0], 1, ['api'], []), new MetricResult([1.0], 1));
        $redactor = new \Watcher\Privacy\Redactor(new \Watcher\Reduce\Normalizer(), ['internal' => '/api/']);

        $labels = (new MetricsReader($src, [$this->def()], 500, new \Watcher\Reduce\Normalizer(), $redactor))->read($this->window())->readings[0]->labels;

        self::assertSame([], $labels);
    }

    public function testRangeSampleExactlyAtTheCutBelongsToTheCurrentWindow(): void
    {
        $w = $this->window();
        $cut = $w->start->getTimestamp();
        $src = new FakeMetricSource(null, static fn (): MetricResult => new MetricResult([5.0, 9.0], 1, [], [$cut - 1, $cut]));

        $r = (new MetricsReader($src, [$this->def('x', 'max', 'range')]))->read($w)->readings[0];

        self::assertSame(5.0, $r->baseline);
        self::assertSame(9.0, $r->current);
    }
}
