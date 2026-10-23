<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Source\LogEvent;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Quickstart scenarios 12-14 and the daily budget alerts (FR-016..FR-022, FR-046). */
final class CostTest extends TestCase
{
    /** One dollar a day; the analyze model costs $0.50 per 1,000 input tokens, the others are free. */
    private const BUDGET = [
        'analysis' => [
            'daily_budget_usd' => 1.0,
            'prices' => ['m-t' => [0, 0, 0, 0], 'm-a' => [500, 0, 0, 0], 'm-e' => [0, 0, 0, 0]],
        ],
    ];

    /** @return list<LogEvent> */
    private static function group(string $type, int $n = 5, Level $level = Level::Error): array
    {
        return Harness::events($level, "$type failed in a unique way", $n, $type);
    }

    /** @param list<LogEvent> $events */
    private static function source(array $events): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /** @return list<string> */
    private static function notices(Harness $h, string $prefix): array
    {
        $out = [];
        foreach ($h->notifier->sent as $s) {
            if (str_contains($s['message']->text, $prefix)) {
                $out[] = $s['message']->text;
            }
        }

        return $out;
    }

    private static function assertEveryKeyExpires(Harness $h): void
    {
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertNotSame([], $h->state->ttls());
        foreach ($h->state->ttls() as $key => $ttl) {
            self::assertGreaterThan(0, $ttl, "state key without an expiry: $key");
        }
    }

    private static function spendToday(Harness $h, float $usd): void
    {
        (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->add(new Usage('m-a', 1, 1), $usd);
    }

    public function testScenario12ExhaustedBudgetMeansTriageOnlyAndOneNoticePerDay(): void
    {
        $h = new Harness(self::BUDGET + ['schedule' => ['window' => 'PT1H']]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->clock->advance('+1 hour');
        self::spendToday($h, 5.0);

        $first = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertNotSame(RunOutcome::Failed, $first->outcome);
        self::assertCount(1, $h->analyzer->triageRequests);
        self::assertSame([], $h->analyzer->requests, 'no analysis once the budget is spent');
        self::assertCount(1, self::notices($h, 'budget exhausted'));

        $h->clock->advance('+1 hour');
        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta'), ...self::group('Gamma')]))->run();

        self::assertCount(2, $h->analyzer->triageRequests);
        self::assertSame([], $h->analyzer->requests);
        self::assertCount(1, self::notices($h, 'budget exhausted'), 'not repeated the same day');
        self::assertEveryKeyExpires($h);
    }

    public function testFailedRunMarksNoExhaustedKeyAndSendsNoNotice(): void
    {
        $h = new Harness(self::BUDGET + ['schedule' => ['window' => 'PT1H']]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->clock->advance('+1 hour');
        self::spendToday($h, 5.0);
        $h->analyzer->failure = new AnalyzerFailed('provider down');

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame([], self::notices($h, 'budget'));
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertSame([], array_filter(array_keys($h->state->all()), static fn (string $k): bool => str_starts_with($k, 'exhausted:') || str_starts_with($k, Keys::ALERT . ':')));
    }

    public function testScenario13OverBudgetInputDropsTheLowestPriorityGroupsAndSaysSo(): void
    {
        $h = new Harness(['analysis' => ['max_input_tokens' => ['triage' => 160]]]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();

        $result = $h->watcher(self::source([
            ...self::group('Alpha'),
            ...self::group('Beta'),
            ...self::group('Gamma', 5, Level::Warning),
            ...self::group('Delta', 5, Level::Warning),
        ]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $sent = $h->analyzer->triageRequests[0]->groups;
        self::assertSame(['Alpha', 'Beta'], array_values(array_map(static fn ($g): ?string => $g->errorType, $sent)));
        self::assertStringContainsString('truncated: 2 groups dropped (budget)', $h->sentTexts()[1]);
    }

    public function testScenario14OnlyEscalationCapLowConfidenceFindingsAreEscalated(): void
    {
        $h = new Harness(['analysis' => ['escalation_cap' => 1]]);
        $h->analyzer->confidence = 0.3;
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $models = array_map(static fn ($r): string => $r->model, $h->analyzer->requests);
        self::assertSame(['m-a', 'm-e'], $models);
        self::assertCount(1, $h->analyzer->requests[1]->groups);
        self::assertCount(2, $h->notifier->sent);
    }

    public function testCrossingFiftyPercentAnnouncesOnceAndALaterRunBetweenThresholdsStaysSilent(): void
    {
        $h = new Harness(self::BUDGET + ['schedule' => ['window' => 'PT1H']]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->clock->advance('+1 hour');
        $h->analyzer->inputTokens = 1000; // $0.50 of $1.00

        $first = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertNotNull($first->usage);
        self::assertCount(1, self::notices($h, 'budget'));
        self::assertStringContainsString('50%', self::notices($h, 'budget')[0]);
        self::assertStringContainsString('today $0.50 of $1.00 (50%)', $h->sentTexts()[count($h->sentTexts()) - 1]);

        $h->clock->advance('+1 hour');
        $h->analyzer->inputTokens = 100; // total $0.55
        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta'), ...self::group('Gamma')]))->run();

        self::assertCount(1, self::notices($h, 'budget'), 'no new threshold, no new alert');
        self::assertStringContainsString('today $0.55 of $1.00 (55%)', $h->sentTexts()[count($h->sentTexts()) - 1]);
        self::assertEveryKeyExpires($h);
    }

    public function testCrossingSeveralThresholdsAtOnceSendsOneNoticeForTheHighest(): void
    {
        $h = new Harness(self::BUDGET);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->analyzer->inputTokens = 1900; // $0.95: 25, 50, 75 and 90 at once

        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        $alerts = self::notices($h, 'budget');
        self::assertCount(1, $alerts);
        self::assertStringContainsString('90%', $alerts[0]);
    }

    public function testTheSameThresholdAnnouncesAgainTheNextDay(): void
    {
        $h = new Harness(self::BUDGET);
        $h->analyzer->inputTokens = 1000;
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();
        $h->nextWindow();

        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta'), ...self::group('Gamma')]))->run();

        self::assertCount(2, array_filter(self::notices($h, 'budget'), static fn (string $t): bool => str_contains($t, '50%')));
    }

    public function testQuietRunChangesNoCountersAndCallsNoModel(): void
    {
        $h = new Harness(self::BUDGET);
        $events = [...self::group('Alpha'), ...self::group('Beta')];
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->watcher(self::source($events))->run();
        $h->nextWindow();
        $calls = count($h->analyzer->triageRequests);
        $sent = count($h->notifier->sent);

        $result = $h->watcher(self::source($events))->run();

        self::assertSame(RunOutcome::Quiet, $result->outcome);
        self::assertNull($result->usage);
        self::assertCount($calls, $h->analyzer->triageRequests);
        self::assertCount($sent, $h->notifier->sent);
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertNull($h->state->get(Keys::spendUsd('svc', $h->clock->now()->format('Y-m-d'))));
    }

    public function testSettledVerdictsAreReusedTheNextDayWithoutAnyModelCall(): void
    {
        $h = new Harness();
        $h->analyzer->verdict = 'noise';
        $events = self::group('Alpha');
        $h->watcher(self::source($events))->run();
        $h->nextWindow();
        $h->watcher(self::source($events))->run();
        self::assertCount(1, $h->analyzer->triageRequests);
        $h->nextWindow();

        $h->watcher(self::source($events))->run();

        self::assertCount(1, $h->analyzer->triageRequests, 'verdict reused, no second triage');
        self::assertSame([], $h->analyzer->requests);
    }

    public function testUsageOfCompletedCallsIsPricedEvenWhenALaterCallFails(): void
    {
        $h = new Harness(['analysis' => ['prices' => ['m-t' => [1000, 0, 0, 0]]]]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->analyzer->analyzeFailure = new AnalyzerFailed('provider unavailable');

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertCount(1, $h->analyzer->triageRequests, 'triage completed before analyze failed');
        $today = (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->today();
        self::assertEqualsWithDelta(1.2, $today['usd'], 1e-9, 'the triage call was paid for');
        self::assertSame(1500, $today['tokens']);
    }

    public function testASuccessfulRunStillCountsEachCallExactlyOnce(): void
    {
        $h = new Harness(['analysis' => ['prices' => ['m-t' => [1000, 0, 0, 0], 'm-a' => [1000, 0, 0, 0]]]]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $today = (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->today();
        self::assertEqualsWithDelta(2.4, $today['usd'], 1e-9);
        self::assertSame(3000, $today['tokens']);
        self::assertEqualsWithDelta(2.4, $result->usage?->costUsd ?? -1, 1e-9);
    }

    public function testAFailedAlertNoticeGivesTheClaimBackSoTheAlertIsNotLostForTheDay(): void
    {
        $h = new Harness(self::BUDGET);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->analyzer->inputTokens = 1000; // $0.50 of $1.00: the 50% alert
        $h->notifier->failOnce(new \Watcher\Notify\DeliveryFailed('slack delivery failed: HTTP 500'));

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertSame([], array_filter(array_keys($h->state->all()), static fn (string $k): bool => str_starts_with($k, Keys::ALERT . ':')), 'claims released');
    }

    public function testAFailedCallWithBilledAttemptsIsStillCounted(): void
    {
        $h = new Harness(['analysis' => ['prices' => ['m-t' => [1000, 0, 0, 0]]]]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->analyzer->failure = new AnalyzerFailed('invalid model output', new Usage('m-t', 1000, 200));

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame('invalid model output', $result->failureReason);
        $today = (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->today();
        self::assertEqualsWithDelta(1.0, $today['usd'], 1e-9, 'the two paid attempts count against the daily cap');
        self::assertSame(1200, $today['tokens']);
    }

    public function testAFailedCallWithoutUsageRecordsNothing(): void
    {
        $h = new Harness();
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->analyzer->failure = new AnalyzerFailed('provider unavailable');

        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        $today = (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->today();
        self::assertSame(0, $today['tokens']);
    }

    public function testAPaidCallWithAnUnpricedModelIsRecordedAtZeroAndFailsClearly(): void
    {
        $h = new Harness();
        $logged = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
        $h->hostLog = $logged;
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->analyzer->usageModel = 'claude-dated-20261001';

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame('no price configured for model', $result->failureReason, 'never a generic internal error');
        $today = (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->today();
        self::assertSame(1500, $today['tokens'], 'the paid call is recorded even without a price');
        self::assertEqualsWithDelta(0.0, $today['usd'], 1e-9);
        self::assertNotSame([], array_filter($logged->lines, static fn (string $l): bool => str_contains($l, 'no price configured')));
    }
}
