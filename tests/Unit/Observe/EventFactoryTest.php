<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use DateTimeImmutable;
use DateTimeZone;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\RouterResult;
use Watcher\Analyze\Severity;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\Observe\Event;
use Watcher\Observe\EventFactory;
use Watcher\Observe\EventSchema;
use Watcher\Observe\RunObservation;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\RunStats;
use Watcher\Operate\RunSummaryData;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FrozenClock;

final class EventFactoryTest extends TestCase
{
    private const NOW = '2026-10-24T06:05:00+00:00';

    public function testARunWithThreeFindingsAndFortyGroups(): void
    {
        $events = $this->factory()->build($this->observation(), $this->summary(), $this->heartbeat());

        $types = array_map(static fn (Event $e): string => $e->type, $events);
        self::assertSame(
            ['run', 'finding', 'finding', 'finding', ...array_fill(0, 20, 'group'), 'budget', 'heartbeat', 'delivery_failure', 'delivery_failure'],
            $types,
        );
        $this->assertAllValid($events);
    }

    public function testRunEventCountsBySeverityAndTrend(): void
    {
        $run = $this->factory()->build($this->observation(), $this->summary(), $this->heartbeat())[0]->toArray(true);

        self::assertSame(['info' => 0, 'warn' => 1, 'error' => 1, 'critical' => 1], $run['findings_by_severity']);
        self::assertSame(['new' => 10, 'grown' => 10, 'unchanged' => 10, 'expected' => 10], $run['groups_by_trend']);
        self::assertSame('reported', $run['outcome']);
        self::assertSame(40, $run['groups_total']);
        self::assertSame(3, $run['findings_total']);
        self::assertSame(['triage' => 1, 'analyze' => 1, 'escalate' => 0], $run['calls']);
        self::assertSame('2026-10-23T06:00:00Z', $run['window_start']);
        self::assertSame('production', $run['environment']);
        self::assertSame(1, $run['escalations']);
        self::assertSame(2, $run['muted']);
    }

    public function testTopGroupsAreOrderedByCountThenFingerprint(): void
    {
        $groups = array_values(array_filter(
            $this->factory()->build($this->observation(), $this->summary(), $this->heartbeat()),
            static fn (Event $e): bool => $e->type === 'group',
        ));

        $pairs = array_map(static fn (Event $e): array => [$e->toArray(false)['count'], $e->toArray(false)['fingerprint']], $groups);
        $sorted = $pairs;
        usort($sorted, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        self::assertSame($sorted, $pairs);
        self::assertSame(range(1, 20), array_map(static fn (Event $e): int => $e->toArray(false)['rank'], $groups));
        self::assertSame(40, max([0, ...array_map(static fn (array $p): int => $p[0], $pairs)]));
    }

    public function testNoGroupBeyondTopGroupsAndZeroMeansNone(): void
    {
        $events = $this->factory(topGroups: 0)->build($this->observation(), $this->summary(), $this->heartbeat());
        self::assertSame([], array_filter($events, static fn (Event $e): bool => $e->type === 'group'));

        $events = $this->factory(topGroups: 3)->build($this->observation(), $this->summary(), $this->heartbeat());
        self::assertCount(3, array_filter($events, static fn (Event $e): bool => $e->type === 'group'));
    }

    public function testEventIdsAreSequentialAndRunIdIsStablePerFactoryAndDiffersBetweenFactories(): void
    {
        $factory = $this->factory();
        $events = $factory->build($this->observation(), $this->summary(), $this->heartbeat());
        $first = $events[0]->toArray(false);

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $first['run_id']);
        foreach ($events as $i => $event) {
            $a = $event->toArray(false);
            self::assertSame($first['run_id'] . '-' . ($i + 1), $a['event_id']);
            self::assertSame($first['run_id'], $a['run_id']);
        }
        self::assertNotSame($first['run_id'], $this->factory()->build($this->observation(), $this->summary(), $this->heartbeat())[0]->toArray(false)['run_id']);
    }

    public function testEnvironmentIsUnsetWhenNull(): void
    {
        $summary = new RunSummaryData(RunOutcome::Quiet, 'bff-a', null, new DateTimeImmutable('2026-10-23T06:00:00Z'), new DateTimeImmutable('2026-10-24T06:00:00Z'), new RunStats(), null, null, false, null);

        self::assertSame('unset', $this->factory()->build(new RunObservation(), $summary, null)[0]->toArray(false)['environment']);
    }

    #[DataProvider('budgetStates')]
    public function testBudgetState(?float $spent, ?float $limit, bool $triageOnly, string $state, ?float $ratio): void
    {
        $obs = new RunObservation();
        $obs->spentTodayUsd = $spent;
        $obs->dailyBudgetUsd = $limit;
        $obs->triageOnly = $triageOnly;

        $budget = $this->only($this->factory()->build($obs, $this->summary(), null), 'budget')->toArray(false);

        self::assertSame($state, $budget['state']);
        self::assertSame($ratio, $budget['usd_ratio']);
    }

    /** @return array<string, array{?float, ?float, bool, string, ?float}> */
    public static function budgetStates(): array
    {
        return [
            '0.89 is ok' => [0.89, 1.0, false, 'ok', 0.89],
            '0.9 is high' => [0.9, 1.0, false, 'high', 0.9],
            '1.0 is exhausted' => [1.0, 1.0, false, 'exhausted', 1.0],
            'triage-only is exhausted' => [0.1, 1.0, true, 'exhausted', 0.1],
            'no cap is ok with a null ratio' => [3.0, null, false, 'ok', null],
        ];
    }

    public function testNoBudgetEventWithoutSpendData(): void
    {
        $types = array_map(static fn (Event $e): string => $e->type, $this->factory()->build(new RunObservation(), $this->summary(), null));
        self::assertNotContains('budget', $types);
        self::assertNotContains('heartbeat', $types);
    }

    public function testTokenRatioCountsForTheState(): void
    {
        $obs = new RunObservation();
        $obs->spentTodayUsd = 0.1;
        $obs->dailyBudgetUsd = 1.0;
        $obs->tokensToday = 95;
        $obs->dailyTokenLimit = 100;

        $budget = $this->only($this->factory()->build($obs, $this->summary(), null), 'budget')->toArray(false);

        self::assertSame('high', $budget['state']);
        self::assertSame(0.95, $budget['token_ratio']);
    }

    public function testDeliveryFailuresUseOnlyTheCode(): void
    {
        $obs = new RunObservation();
        $obs->deliveryFailed('slack', 'digest', new DeliveryFailed('slack delivery failed: https://hooks.example/T0/secret', 429));
        $obs->deliveryFailed('telegram', 'notice', new DeliveryFailed('boom', RunObservation::NETWORK));
        $obs->deliveryFailed('discord', 'weekly', new DeliveryFailed('boom', 503));
        $obs->deliveryFailed('slack', 'other', new DeliveryFailed('boom'));

        $failures = array_map(
            static fn (Event $e): array => $e->toArray(true),
            array_values(array_filter($this->factory()->build($obs, $this->summary(), null), static fn (Event $e): bool => $e->type === 'delivery_failure')),
        );

        self::assertSame(['http_429', 'network', 'http_5xx', 'other'], array_column($failures, 'status_class'));
        self::assertSame([429, null, 503, null], array_column($failures, 'http_status'));
        self::assertStringNotContainsString('secret', (string) json_encode($failures));
    }

    public function testADegradedHeartbeatIsAValidEventWithItsStateKept(): void
    {
        // `degraded` (spec 008): the last run worked but the pod events could not be read; `watcher status` exits 1 for it.
        $events = $this->factory()->build($this->observation(), $this->summary(), $this->heartbeat('degraded'));

        self::assertSame('degraded', $this->only($events, 'heartbeat')->toArray(false)['state']);
        $this->assertAllValid($events);
    }

    public function testHeartbeatCarriesPriorSinkWarnings(): void
    {
        $obs = new RunObservation();
        $obs->priorSinkFailures = ['http', 'file'];

        $hb = $this->only($this->factory()->build($obs, $this->summary(), $this->heartbeat()), 'heartbeat')->toArray(false);

        self::assertSame(['sink_failed:http', 'sink_failed:file'], $hb['warnings']);
        self::assertSame('ok', $hb['state']);
        self::assertSame(129600, $hb['stale_after_seconds']);
        self::assertSame(3600, $hb['age_seconds']);
    }

    public function testFindingEventsTakeGroupDataAndKeepTextApart(): void
    {
        $events = $this->factory()->build($this->observation(), $this->summary(), null);
        $finding = $events[1];

        $without = $finding->toArray(false);
        self::assertArrayNotHasKey('title', $without);
        self::assertSame(1, $without['finding_index']);
        self::assertSame('000000000000', $without['fingerprint']);
        self::assertSame(['000000000000'], $without['fingerprints']);
        self::assertSame('new', $without['group_trend']);
        self::assertSame(40, $without['group_count']);
        self::assertTrue($without['after_release']);

        $with = $finding->toArray(true);
        self::assertSame('Title 0', $with['title']);
        self::assertSame('cause 0', $with['hypothesis']);
        self::assertSame('act 0', $with['next_step']);
        self::assertSame('check 0', $with['check']);
        self::assertSame(['rel'], $with['related']);
        self::assertSame(EventSchema::fields('finding', true), array_keys($with));
    }

    public function testSkippedDoneEmitsRunOnlyAndNotDueEmitsNothing(): void
    {
        $obs = $this->observation();
        $done = $this->factory()->build($obs, new RunSummaryData(RunOutcome::SkippedDone, 'bff-a'), $this->heartbeat());
        self::assertSame(['run'], array_map(static fn (Event $e): string => $e->type, $done));
        self::assertNull($done[0]->toArray(false)['window_start']);
        $this->assertAllValid($done);

        foreach ([RunOutcome::SkippedNotDue, RunOutcome::InvalidConfig, RunOutcome::Disabled] as $outcome) {
            self::assertSame([], $this->factory()->build($obs, new RunSummaryData($outcome, 'bff-a'), null));
        }
    }

    public function testConfigWarningsBecomeEvents(): void
    {
        $obs = new RunObservation();
        $obs->warn('event_cap_hit', 'source.event_cap', 5000);
        $obs->warn('include_text_external', 'observe.http.include_text');

        $w = array_map(
            static fn (Event $e): array => $e->toArray(false),
            array_values(array_filter($this->factory()->build($obs, $this->summary(), null), static fn (Event $e): bool => $e->type === 'config_warning')),
        );

        self::assertSame(['event_cap_hit', 'include_text_external'], array_column($w, 'code'));
        self::assertSame([5000, null], array_column($w, 'count'));
    }

    private function factory(int $topGroups = 20): EventFactory
    {
        return new EventFactory(new FrozenClock(new DateTimeImmutable(self::NOW)), 'test', $topGroups);
    }

    private function summary(): RunSummaryData
    {
        $stats = new RunStats();
        $stats->eventsRead = 1200;
        $stats->groups = 40;
        $stats->candidates = 6;
        $stats->findings = 3;
        $stats->calls = ['triage' => 1, 'analyze' => 1, 'escalate' => 0];
        $stats->tokensIn = 9000;
        $stats->tokensOut = 800;
        $stats->costUsd = 0.0412;
        $stats->durationMs = 4200;

        return new RunSummaryData(RunOutcome::Reported, 'bff-a', null, new DateTimeImmutable('2026-10-23T06:00:00Z'), new DateTimeImmutable('2026-10-24T06:00:00Z'), $stats, 0.2, '1.4.2', false, 'production');
    }

    private function heartbeat(string $state = 'ok'): HeartbeatStatus
    {
        $now = new DateTimeImmutable(self::NOW);

        return new HeartbeatStatus($state, ['outcome' => 'reported'], 3600, 129600, $now, $now->modify('-1 hour'), null);
    }

    private function observation(): RunObservation
    {
        $obs = new RunObservation();
        $utc = new DateTimeZone('UTC');
        $trends = [GroupStatusLabel::New, GroupStatusLabel::Grown, GroupStatusLabel::Unchanged, GroupStatusLabel::Expected];
        for ($i = 0; $i < 40; ++$i) {
            $fp = sprintf('%012x', $i);
            // counts 40 down to 1, two ties at the top so the fingerprint breaks them
            $count = max(1, 40 - $i + ($i === 1 ? 1 : 0));
            $obs->groups[$fp] = new RedactedGroup($fp, 'tpl', Level::Error, 'App\\TimeoutException', ['app/A.php:1'], $count, $i % 2 === 0 ? null : 3, $trends[$i % 4], ['payments'], new DateTimeImmutable('2026-10-23T07:00:00', $utc), new DateTimeImmutable('2026-10-24T05:59:00', $utc));
        }
        $sev = [Severity::Critical, Severity::Error, Severity::Warn];
        foreach ($sev as $i => $s) {
            $obs->findings[] = new Finding([sprintf('%012x', $i)], FindingKind::Bug, $s, "Title $i", "evidence $i", "cause $i", "act $i", 0.8, $i === 0, "check $i", ['rel']);
        }
        $obs->newAfterRelease = ['000000000000'];
        $obs->router = new RouterResult($obs->findings, [], 1, 0, 6, 0);
        $obs->muted = 2;
        $obs->spentTodayUsd = 0.2;
        $obs->dailyBudgetUsd = 1.0;
        $obs->deliveryFailed('slack', 'digest', new DeliveryFailed('x', 429));
        $obs->deliveryFailed('slack', 'notice', new DeliveryFailed('x', RunObservation::NETWORK));

        return $obs;
    }

    /** @param list<Event> $events */
    private function only(array $events, string $type): Event
    {
        $found = array_values(array_filter($events, static fn (Event $e): bool => $e->type === $type));
        self::assertCount(1, $found, $type);

        return $found[0];
    }

    /** @param list<Event> $events */
    private function assertAllValid(array $events): void
    {
        $schema = json_decode((string) file_get_contents(__DIR__ . '/../../../docs/schema/watcher.event.v1.schema.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($events as $event) {
            foreach ([false, true] as $withText) {
                $result = (new Validator())->validate(json_decode((string) json_encode($event->toArray($withText))), $schema);
                self::assertTrue($result->isValid(), $event->type . ($withText ? ' with text' : ''));
            }
        }
    }
}
