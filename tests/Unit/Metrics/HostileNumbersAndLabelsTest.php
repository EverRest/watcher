<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\PayloadExtras;
use Watcher\Metrics\MetricAnomalies;
use Watcher\Metrics\MetricDefinition;
use Watcher\Metrics\MetricReading;
use Watcher\Metrics\MetricResult;
use Watcher\Metrics\MetricsContext;
use Watcher\Metrics\MetricsReader;
use Watcher\Metrics\QueryTemplate;
use Watcher\Schedule\Window;
use Watcher\Tests\Fakes\FakeMetricSource;

/** Review H1 and M3: a hostile or degenerate backend answer never fails the run and never puts markup in the digest line. */
final class HostileNumbersAndLabelsTest extends TestCase
{
    /** @return iterable<string, array{float, float}> */
    public static function ratios(): iterable
    {
        yield '1e10 / 1e-320' => [1.0e10, 1.0e-320];
        yield '1.7e308 / 1e-300' => [1.7e308, 1.0e-300];
        yield '1e300 / 1e-10' => [1.0e300, 1.0e-10];
    }

    #[DataProvider('ratios')]
    public function testANonFiniteRatioIsNullAndNothingDownstreamThrowsOrWarns(float $cur, float $base): void
    {
        $r = new MetricReading('m', '', $cur, $base, 'ok', 1, []);
        self::assertNull($r->relativeChange());

        $ctx = new MetricsContext([$r]);
        self::assertStringContainsString('change not computable', $ctx->line());
        $m = PayloadExtras::fromContext($ctx);
        self::assertNotNull($m);
        CompactPayload::encode([], [], $m); // JSON_THROW_ON_ERROR must not trip
        $this->addToAssertionCount(1);
        $defs = [new MetricDefinition('m', QueryTemplate::compile('m', 'up', 'svc', []), 'max', ['relative' => 0.5, 'floor' => 0.0])];
        $found = (new MetricAnomalies('svc', $defs, 3, 2.0))->detect($ctx, [], new Window(new DateTimeImmutable('2026-10-07T06:00:00Z'), new DateTimeImmutable('2026-10-08T06:00:00Z')), []);
        self::assertSame([], $found->candidates); // no ratio, no anomaly, no warning
    }

    public function testNanNegativeZeroAndANegativeBaselineAreNull(): void
    {
        foreach ([[NAN, 1.0], [1.0, NAN], [1.0, -0.0], [1.0, 0.0], [1.0, -2.0]] as [$c, $b]) {
            self::assertNull((new MetricReading('m', '', $c, $b, 'ok', 1, []))->relativeChange(), sprintf('%s / %s', var_export($c, true), var_export($b, true)));
        }
        self::assertSame(1.0, (new MetricReading('m', '', 2.0, 1.0, 'ok', 1, []))->relativeChange());
    }

    public function testAHugeFiniteRatioStillMakesAnAnomalyWithoutAnIntOverflow(): void
    {
        $r = new MetricReading('m', '', 1.0e300, 1.0, 'ok', 1, []);
        $ctx = new MetricsContext([$r]);
        $defs = [new MetricDefinition('m', QueryTemplate::compile('m', 'up', 'svc', []), 'max', ['relative' => 0.5, 'floor' => 0.0])];
        $found = (new MetricAnomalies('svc', $defs, 3, 2.0))->detect($ctx, [], new Window(new DateTimeImmutable('2026-10-07T06:00:00Z'), new DateTimeImmutable('2026-10-08T06:00:00Z')), []);

        self::assertCount(1, $found->candidates);
        self::assertGreaterThan(0, $found->candidates[0]->group->count);
        self::assertStringContainsString('%', $ctx->line());
        CompactPayload::encode([], [], PayloadExtras::fromContext($ctx));
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function labels(): iterable
    {
        foreach (["pay\x1fments", "pay\u{FFFE}ments", "pay\u{200B}ments", '<!channel>', '*bold*', '`code`', '||spoiler||', '[a](b)', '@here', 'a b', str_repeat('a', 65), "pay\nments", ''] as $l) {
            yield json_encode($l) ?: 'x' => [$l];
        }
    }

    #[DataProvider('labels')]
    public function testOnlyPlainShortLabelsReachTheDigestLine(string $label): void
    {
        $source = new FakeMetricSource(static fn (): MetricResult => new MetricResult([1.0], 1, [$label, 'payments_eu-west']));
        $defs = [new MetricDefinition('m', QueryTemplate::compile('m', 'up', 'svc', []), 'max')];
        $ctx = (new MetricsReader($source, $defs))->read(new Window(new DateTimeImmutable('2026-10-07T06:00:00Z'), new DateTimeImmutable('2026-10-08T06:00:00Z')));

        self::assertSame(['payments_eu-west'], $ctx->readings[0]->labels);
        self::assertSame(1, preg_match('/^[\x20-\x7e]+$/D', $ctx->line()));
        self::assertStringNotContainsString('<', $ctx->line());
    }

}
