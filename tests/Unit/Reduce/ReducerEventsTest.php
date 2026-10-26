<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\Reducer;
use Watcher\Select\WatchRules;
use Watcher\Source\LogEvent;

/** Spec 008 R7: occurrence weight, distinct pods and the pod-event marker cross the Reducer. */
final class ReducerEventsTest extends TestCase
{
    private function pod(string $message, int $occurrences, ?string $podId, string $time = '2026-01-01'): LogEvent
    {
        return new LogEvent(
            new DateTimeImmutable($time),
            Level::Error,
            $message,
            'OOMKilled',
            [],
            [],
            [],
            $occurrences,
            $podId,
            'k8s-events',
            'Deployment',
        );
    }

    public function testLogEventDefaultsToOneOccurrenceAndNoPod(): void
    {
        $e = new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Error, 'x', null, [], [], []);

        self::assertSame(1, $e->occurrences);
        self::assertNull($e->podId);
    }

    public function testOccurrencesWeighTheCountAndDistinctPodsAreCounted(): void
    {
        $groups = (new Reducer(new Normalizer(), 3))->reduce([
            $this->pod('OOMKilled: container <STR> was killed', 5, 'aaaaaaaa', '2026-01-01'),
            $this->pod('OOMKilled: container <STR> was killed', 3, 'bbbbbbbb', '2026-01-02'),
            $this->pod('OOMKilled: container <STR> was killed', 2, 'aaaaaaaa', '2026-01-03'),
        ]);

        self::assertCount(1, $groups);
        self::assertSame(10, $groups[0]->count);
        self::assertSame(2, $groups[0]->pods);
        self::assertSame('k8s-events', $groups[0]->origin);
        self::assertSame('Deployment', $groups[0]->workloadKind);
        self::assertSame('2026-01-01', $groups[0]->firstSeen->format('Y-m-d'));
        self::assertSame('2026-01-03', $groups[0]->lastSeen->format('Y-m-d'));
    }

    public function testAGroupAcrossWorkloadKindsNamesNone(): void
    {
        $a = new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Error, 'container restarted', 'ContainerRestarted', [], [], [], 2, 'aaaaaaaa', 'k8s-events', 'Deployment');
        $b = new LogEvent(new DateTimeImmutable('2026-01-02'), Level::Error, 'container restarted', 'ContainerRestarted', [], [], [], 1, 'bbbbbbbb', 'k8s-events', 'CronJob');
        $g = (new Reducer(new Normalizer(), 3))->reduce([$a, $b])[0];

        self::assertNull($g->workloadKind);
        self::assertSame(2, $g->pods);
    }

    public function testALogStreamCannotClaimToBeAPodEvent(): void
    {
        $e = new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Error, 'Lock timeout', null, [], [], ['source' => 'k8s-events', 'workload_kind' => 'jsmith@example.com'], 5, 'aaaaaaaa');
        $g = (new Reducer(new Normalizer(), 3))->reduce([$e])[0];

        self::assertNull($g->origin);
        self::assertNull($g->workloadKind);
        self::assertNull($g->pods);
    }

    public function testOnlyKnownWorkloadKindsAndTheKubernetesOriginSurvive(): void
    {
        $spoofed = new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Error, 'x', null, [], [], [], 1, null, 'loki', 'Deployment');
        $freeText = new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Error, 'x', null, [], [], [], 1, null, 'k8s-events', 'Olena Shevchenko');

        self::assertNull($spoofed->origin);
        self::assertNull($spoofed->workloadKind);
        self::assertSame('k8s-events', $freeText->origin);
        self::assertNull($freeText->workloadKind);
    }

    public function testOneEventWithEnoughOccurrencesKeepsItsText(): void
    {
        $groups = (new Reducer(new Normalizer(), 3))->reduce([$this->pod('OOMKilled: container <STR> was killed', 14, 'aaaaaaaa')]);

        self::assertNotNull($groups[0]->template);
    }

    public function testLogGroupsCarryNoOrigin(): void
    {
        $e = new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Error, 'Lock timeout', null, [], [], []);
        $g = (new Reducer(new Normalizer(), 3))->reduce([$e])[0];

        self::assertNull($g->origin);
        self::assertNull($g->pods);
        self::assertNull($g->workloadKind);
    }

    public function testComparisonAndRulesAndRedactionKeepTheMarker(): void
    {
        $g = (new Reducer(new Normalizer(), 3))->reduce([$this->pod('OOMKilled: container <STR> was killed', 4, 'aaaaaaaa')])[0];

        $compared = $g->withComparison(2, GroupStatus::Grown);
        self::assertSame('k8s-events', $compared->origin);
        self::assertSame(1, $compared->pods);

        $previous = $g->withPrevious(2, 1.5);
        self::assertSame('Deployment', $previous->workloadKind);

        $ruled = (new WatchRules(['oom' => ['error_type' => 'OOMKilled']]))->apply([$g], ['x' => []]);
        self::assertSame('k8s-events', $ruled[0]->origin);

        $redacted = (new Redactor(new Normalizer(), [], []))->redact($g);
        self::assertSame('k8s-events', $redacted->origin);
        self::assertSame(1, $redacted->pods);
        self::assertSame('Deployment', $redacted->workloadKind);
    }
}
