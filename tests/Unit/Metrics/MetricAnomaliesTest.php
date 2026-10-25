<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Metrics\MetricAnomalies;
use Watcher\Metrics\MetricDefinition;
use Watcher\Metrics\MetricReading;
use Watcher\Metrics\MetricsContext;
use Watcher\Metrics\QueryTemplate;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\ProblemGroup;
use Watcher\Schedule\Window;
use Watcher\Select\Candidate;
use Watcher\Select\CandidateReason;

final class MetricAnomaliesTest extends TestCase
{
    private function window(): Window
    {
        return Window::endingAt(new DateTimeImmutable('2026-10-23T00:00:00Z'));
    }

    /** @param array{relative: float, floor: float}|null $threshold */
    private function def(string $name, ?array $threshold): MetricDefinition
    {
        return new MetricDefinition($name, QueryTemplate::compile($name, 'q', 'svc', []), null, $threshold);
    }

    private function reading(string $name, ?float $current, ?float $baseline): MetricReading
    {
        $state = $current === null ? 'no_data' : ($baseline === null || $baseline <= 0 ? 'no_baseline' : 'ok');

        return new MetricReading($name, 'ms', $current, $baseline, $state, 1, []);
    }

    private function logCandidate(Level $level): Candidate
    {
        $t = new DateTimeImmutable('2026-10-22T12:00:00Z');

        return new Candidate(new ProblemGroup('abc', 'x', $level, null, [], 3, $t, $t), [CandidateReason::New], 40);
    }

    /**
     * @param list<MetricReading>   $readings
     * @param list<MetricDefinition> $defs
     * @param list<Candidate>       $explaining
     * @param array<string, int>    $prev
     */
    private function detectWith(array $readings, array $defs, array $explaining = [], int $max = 3, array $prev = []): \Watcher\Metrics\AnomalyResult
    {
        return (new MetricAnomalies('svc', $defs, $max, 2.0))->detect(new MetricsContext($readings), $explaining, $this->window(), $prev);
    }

    private const T = ['relative' => 0.5, 'floor' => 100.0];

    /** @return array<string, array{?float, ?float, ?string, int}> */
    public static function table(): array
    {
        return [
            'tripled, silent logs' => [900.0, 300.0, null, 1],
            'tripled, Error group explains' => [900.0, 300.0, 'error', 0],
            'tripled, Warning group only' => [900.0, 300.0, 'warning', 1],
            'relative met, below floor' => [90.0, 30.0, null, 0],
            'floor met, relative not' => [200.0, 190.0, null, 0],
            'no baseline' => [900.0, null, null, 0],
            'no data' => [null, 300.0, null, 0],
            'down is not an anomaly' => [100.0, 900.0, null, 0],
        ];
    }

    #[DataProvider('table')]
    public function testSelectionTable(?float $cur, ?float $base, ?string $explain, int $expected): void
    {
        $explaining = $explain === null ? [] : [$this->logCandidate($explain === 'error' ? Level::Error : Level::Warning)];

        $res = $this->detectWith([$this->reading('p95', $cur, $base)], [$this->def('p95', self::T)], $explaining);

        self::assertCount($expected, $res->candidates);
    }

    public function testRelativeExactlyAtTheThresholdIsAnAnomaly(): void
    {
        self::assertCount(1, $this->detectWith([$this->reading('p95', 450.0, 300.0)], [$this->def('p95', self::T)])->candidates); // +50 % == relative
        self::assertCount(0, $this->detectWith([$this->reading('p95', 449.0, 300.0)], [$this->def('p95', self::T)])->candidates);
    }

    public function testACriticalLogCandidateExplainsTheMove(): void
    {
        $res = $this->detectWith([$this->reading('p95', 900.0, 300.0)], [$this->def('p95', self::T)], [$this->logCandidate(Level::Critical)]);

        self::assertCount(0, $res->candidates);
    }

    public function testMetricWithoutThresholdIsContextOnly(): void
    {
        self::assertCount(0, $this->detectWith([$this->reading('p95', 900.0, 300.0)], [$this->def('p95', null)])->candidates);
    }

    public function testGroupShapeAndStableFingerprint(): void
    {
        $res = $this->detectWith([$this->reading('p95', 900.0, 300.0)], [$this->def('p95', self::T)]);
        $res2 = $this->detectWith([$this->reading('p95', 910.0, 300.0)], [$this->def('p95', self::T)]);

        $c = $res->candidates[0];
        $g = $c->group;
        self::assertMatchesRegularExpression('/^metric:[0-9a-f]{12}$/', $g->fingerprint);
        self::assertSame(substr(hash('sha256', 'svc|p95'), 0, 12), substr($g->fingerprint, 7));
        self::assertSame($g->fingerprint, $res2->candidates[0]->group->fingerprint);
        self::assertSame('metric anomaly: p95', $g->template);
        self::assertSame(Level::Warning, $g->level);
        self::assertNull($g->errorType);
        self::assertSame([], $g->frames);
        self::assertSame(200, $g->count); // (900-300)/300 = 200 %
        self::assertSame([CandidateReason::MetricAnomaly], $c->reasons);
        self::assertSame(5 * 10 + Level::Warning->value, $c->priority);
        self::assertEquals($this->window()->start, $g->firstSeen);
        self::assertEquals($this->window()->end, $g->lastSeen);
        self::assertSame('p95', $res->metricByFingerprint[$g->fingerprint]);
    }

    public function testCountIsAtLeastOne(): void
    {
        $d = [new MetricDefinition('a', QueryTemplate::compile('a', 'q', 'svc', []), null, ['relative' => 0.001, 'floor' => 0.0])];

        $res = $this->detectWith([$this->reading('a', 100.4, 100.0)], $d);

        self::assertSame(1, $res->candidates[0]->group->count);
    }

    public function testTemplateHoldsNoDigitFromTheNumbers(): void
    {
        $res = $this->detectWith([$this->reading('p95', 987.0, 300.0)], [$this->def('p95', self::T)]);

        self::assertStringNotContainsString('987', (string) $res->candidates[0]->group->template);
        self::assertStringNotContainsString('300', (string) $res->candidates[0]->group->template);
    }

    public function testStatusComesFromThePreviousSnapshotOnly(): void
    {
        $fp = $this->detectWith([$this->reading('p95', 900.0, 300.0)], [$this->def('p95', self::T)])->candidates[0]->group->fingerprint;

        $new = $this->detectWith([$this->reading('p95', 900.0, 300.0)], [$this->def('p95', self::T)])->candidates[0]->group;
        $same = $this->detectWith([$this->reading('p95', 900.0, 300.0)], [$this->def('p95', self::T)], [], 3, [$fp => 200])->candidates[0]->group;
        $grown = $this->detectWith([$this->reading('p95', 1500.0, 300.0)], [$this->def('p95', self::T)], [], 3, [$fp => 200])->candidates[0]->group;

        self::assertSame(GroupStatus::New, $new->status);
        self::assertSame(GroupStatus::Unchanged, $same->status);
        self::assertSame(200, $same->previousCount);
        self::assertSame(GroupStatus::Grown, $grown->status); // 400 >= 2 * 200
    }

    public function testTwoOverAreTwoDistinctGroupsOrderedByLargestChange(): void
    {
        $res = $this->detectWith(
            [$this->reading('b_lat', 900.0, 300.0), $this->reading('a_lat', 1500.0, 300.0)],
            [$this->def('b_lat', self::T), $this->def('a_lat', self::T)],
        );

        self::assertSame(['metric anomaly: a_lat', 'metric anomaly: b_lat'], array_map(static fn (Candidate $c): ?string => $c->group->template, $res->candidates));
        self::assertNotSame($res->candidates[0]->group->fingerprint, $res->candidates[1]->group->fingerprint);
    }

    public function testCapKeepsTheLargestAndTiesBreakByNameAndTruncationIsCounted(): void
    {
        $names = ['e', 'd', 'c', 'b', 'a'];
        $res = $this->detectWith(
            array_map(fn (string $n): MetricReading => $this->reading($n, 900.0, 300.0), $names),
            array_map(fn (string $n): MetricDefinition => $this->def($n, self::T), $names),
        );

        self::assertSame(['a', 'b', 'c'], array_values($res->metricByFingerprint));
        self::assertSame(2, $res->truncated);
    }
}
