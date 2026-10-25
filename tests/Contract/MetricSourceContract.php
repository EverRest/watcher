<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Metrics\MetricSource;
use Watcher\Source\SourceUnavailable;

/** Behaviours every MetricSource must have; subclasses script their backend in the hooks. */
abstract class MetricSourceContract extends TestCase
{
    protected const TOKENS = ['unreachable', 'unauthorized', 'timeout', 'bad_response', 'rate_limited', 'too_large', 'window_too_old'];

    protected function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-23T00:00:00Z');
    }

    /** @param list<string> $raw sample strings, one series each, as the backend would send them (may hold NaN) */
    abstract protected function makeSourceWithSamples(array $raw): MetricSource;

    abstract protected function makeSourceFailing(int $httpStatus): MetricSource;

    abstract protected function secret(): string;

    public function testEmptyResultIsNoDataNotZero(): void
    {
        $r = $this->makeSourceWithSamples([])->instant('q', $this->at());

        self::assertSame([], $r->values);
        self::assertSame(0, $r->series);
    }

    public function testOnlyFiniteValuesAreKeptAndSeriesAreCountedBeforeFiltering(): void
    {
        $r = $this->makeSourceWithSamples(['NaN', '+Inf', '7', '2.5'])->instant('q', $this->at());

        self::assertSame([7.0, 2.5], $r->values);
        self::assertSame(4, $r->series);
        self::assertSame([], $r->allowedLabelValues);
    }

    /** @return array<int|string, array{int}> */
    public static function failures(): array
    {
        return ['network' => [0], '401' => [401], '403' => [403], '429' => [429], '500' => [500], '503' => [503]];
    }

    #[DataProvider('failures')]
    public function testFailuresBecomeSourceUnavailableWithAFixedToken(int $status): void
    {
        try {
            $this->makeSourceFailing($status)->instant('q', $this->at());
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertContains($e->getMessage(), self::TOKENS);
            self::assertStringNotContainsString($this->secret(), $e->getMessage());
        }
    }
}
