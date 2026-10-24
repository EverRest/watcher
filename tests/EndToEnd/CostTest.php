<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Budget;
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
    /** One dollar a day; the analyze model costs $0.50 per 100,000 input tokens ($5 per million), the others are free. */
    private const BUDGET = [
        'analysis' => [
            'daily_budget_usd' => 1.0,
            'prices' => ['m-t' => [0, 0, 0, 0], 'm-a' => [5, 0, 0, 0], 'm-e' => [0, 0, 0, 0]],
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

    public function testTriageOnlyDayStillSaysHowManyInterestingGroupsWereNotAnalysed(): void
    {
        $h = new Harness(self::BUDGET + ['schedule' => ['window' => 'PT1H']]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->clock->advance('+1 hour');
        self::spendToday($h, 5.0);

        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta'), ...self::group('Gamma')]))->run();

        self::assertStringContainsString('3 group(s) flagged interesting were not analysed', implode("\n", $h->sentTexts()), 'a missed analysis must not look like a quiet day');
    }

    public function testTopMCutIsReportedAsNotAnalysed(): void
    {
        $h = new Harness(['selection' => ['top_m' => 1]]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();

        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta'), ...self::group('Gamma')]))->run();

        self::assertStringContainsString('2 group(s) flagged interesting were not analysed', implode("\n", $h->sentTexts()));
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
        $h = new Harness(['analysis' => ['max_input_tokens' => ['triage' => Budget::fixedTokens('triage', '') + 160]]]);
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
        self::assertSame(['Alpha', 'Beta'], array_map(static fn ($g): ?string => $g->errorType, $sent));
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
        $h->analyzer->inputTokens = 100000; // $0.50 of $1.00

        $first = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertNotNull($first->usage);
        self::assertCount(1, self::notices($h, 'budget'));
        self::assertStringContainsString('50%', self::notices($h, 'budget')[0]);
        self::assertStringContainsString('today $0.50 of $1.00 (50%)', $h->sentTexts()[count($h->sentTexts()) - 1]);

        $h->clock->advance('+1 hour');
        $h->analyzer->inputTokens = 10000; // total $0.55
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
        $h->analyzer->inputTokens = 190000; // $0.95: 25, 50, 75 and 90 at once

        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        $alerts = self::notices($h, 'budget');
        self::assertCount(1, $alerts);
        self::assertStringContainsString('90%', $alerts[0]);
    }

    public function testTheSameThresholdAnnouncesAgainTheNextDay(): void
    {
        $h = new Harness(self::BUDGET);
        $h->analyzer->inputTokens = 100000;
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
        $events = self::group('Alpha', 5, Level::Warning);
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
        $h = new Harness(['analysis' => ['daily_budget_usd' => 100.0, 'prices' => ['m-t' => [1000, 0, 0, 0]]]]);
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
        $h = new Harness(['analysis' => ['daily_budget_usd' => 100.0, 'prices' => ['m-t' => [1000, 0, 0, 0], 'm-a' => [1000, 0, 0, 0]]]]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $today = (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->today();
        self::assertEqualsWithDelta(2.4, $today['usd'], 1e-9);
        self::assertSame(3000, $today['tokens']);
        self::assertEqualsWithDelta(2.4, $result->usage->costUsd ?? -1, 1e-9);
    }

    public function testAFailedAlertNoticeGivesTheClaimBackAndNeverAbortsTheDigest(): void
    {
        $h = new Harness(self::BUDGET);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->analyzer->inputTokens = 100000; // $0.50 of $1.00: the 50% alert
        $h->notifier->failOnce(new \Watcher\Notify\DeliveryFailed('slack delivery failed: HTTP 500'));
        $log = new \Watcher\Tests\Fakes\SpyLogger();
        $h->hostLog = $log;

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome, 'the digest still goes out');
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertSame([], array_filter(array_keys($h->state->all()), static fn (string $k): bool => str_starts_with($k, Keys::ALERT . ':')), 'claims released');
        self::assertCount(1, $log->records);
        self::assertStringContainsString('slack delivery failed: HTTP 500', $log->records[0][1]);
    }

    public function testADeliveryFailureIsLoggedAsClassAndMaskedMessageNeverTheWebhook(): void
    {
        $h = new Harness(self::BUDGET);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->analyzer->inputTokens = 100000;
        $h->notifier->failOnce(new \Watcher\Notify\DeliveryFailed('Unable to parse URI: https://hooks.slack.com:port/services/T0FAKE000/B0FAKE000/FAKEFAKEFAKE token=abc12345678'));
        $log = new \Watcher\Tests\Fakes\SpyLogger();
        $h->hostLog = $log;

        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertCount(1, $log->records);
        self::assertStringStartsWith('budget alert not delivered: Watcher\\Notify\\DeliveryFailed: ', $log->records[0][1]);
        self::assertStringNotContainsString('hooks.slack.com', $log->records[0][1]);
        self::assertStringNotContainsString('FAKEFAKE', $log->records[0][1]);
        self::assertStringNotContainsString('abc12345678', $log->records[0][1]);
    }

    public function testAFailedExhaustedNoticeNeverAbortsTheDigest(): void
    {
        $h = new Harness(self::BUDGET + ['schedule' => ['window' => 'PT1H']]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->clock->advance('+1 hour');
        self::spendToday($h, 5.0);
        $h->notifier->failOnce(new \Watcher\Notify\DeliveryFailed('slack delivery failed: HTTP 500'));

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertNotSame(RunOutcome::Failed, $result->outcome);
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertSame([], array_filter(array_keys($h->state->all()), static fn (string $k): bool => str_starts_with($k, 'exhausted:')), 'key given back so a later run retries');
    }

    public function testAFailedCallWithBilledAttemptsIsStillCounted(): void
    {
        $h = new Harness(['analysis' => ['daily_budget_usd' => 100.0, 'prices' => ['m-t' => [1000, 0, 0, 0]]]]);
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

    public function testAPaidCallWithAnUnpricedModelIsRecordedAtZeroKeepsItsResultAndWarnsOnce(): void
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

        self::assertSame(RunOutcome::Reported, $result->outcome, 'the paid result is kept');
        $today = (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->today();
        self::assertGreaterThan(0, $today['tokens'], 'the paid calls are recorded even without a price');
        self::assertEqualsWithDelta(0.0, $today['usd'], 1e-9);
        self::assertCount(1, array_filter($logged->lines, static fn (string $l): bool => str_contains($l, 'no price configured')), 'warned once per model, not per call');
    }

    private function nextHourWithSpent(Harness $h, float $usd): void
    {
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->clock->advance('+1 hour');
        self::spendToday($h, $usd);
    }

    public function testH7RunStartingJustUnderTheCapAnalysesNothingThatCouldOvershootIt(): void
    {
        $h = new Harness(['schedule' => ['window' => 'PT1H']]); // $0.50 a day; analysis worst case is about $0.065
        $this->nextHourWithSpent($h, 0.45);

        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertCount(1, $h->analyzer->triageRequests, 'triage (worst case about $0.01) still fits');
        self::assertSame([], $h->analyzer->requests, 'analysis does not');
        self::assertStringContainsString('2 group(s) flagged interesting were not analysed', implode("\n", $h->sentTexts()));
        $today = (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'svc'))->today();
        self::assertLessThan(0.50, $today['usd']);
    }

    public function testH7TriageThatCouldOvershootIsSkippedAndTheDigestSaysSo(): void
    {
        $h = new Harness(['schedule' => ['window' => 'PT1H']]);
        $this->nextHourWithSpent($h, 0.499);

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertNotSame(RunOutcome::Failed, $result->outcome);
        self::assertSame([], $h->analyzer->allRequests(), 'no model call at all');
        self::assertStringContainsString('2 group(s) not triaged: the daily budget would have been exceeded', implode("\n", $h->sentTexts()), 'not a quiet day');
    }

    public function testH7TokenLimitIsReservedTheSameWay(): void
    {
        // 9000 (was 5000): a step now reserves both attempts (F5), and the limit still has to fit the triage worst case but not the analysis one
        $h = new Harness(['schedule' => ['window' => 'PT1H'], 'analysis' => ['daily_token_limit' => 9000]]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->clock->advance('+1 hour');

        $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertCount(1, $h->analyzer->triageRequests, 'triage fits in 9000 tokens');
        self::assertSame([], $h->analyzer->requests, '1500 tokens used + the analysis worst case (two attempts, output cap 4000) exceed 9000');
        self::assertStringContainsString('were not analysed', implode("\n", $h->sentTexts()));
    }

    public function testH7EscalationIsReservedAndTheFirstPassFindingIsKept(): void
    {
        $h = new Harness(['analysis' => ['prices' => ['m-e' => [50, 250, 0, 0]]]]); // an escalation would cost about $0.60
        $h->analyzer->confidence = 0.3;
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();

        $result = $h->watcher(self::source([...self::group('Alpha'), ...self::group('Beta')]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertSame(['m-a'], array_values(array_unique(array_map(static fn ($r) => $r->model, $h->analyzer->requests))), 'never the escalation model');
        self::assertStringContainsString('2 escalation(s) skipped', implode("\n", $h->sentTexts()));
    }

    public function testW3bEscalationOverItsInputLimitIsSkippedAndCounted(): void
    {
        $h = new Harness(['analysis' => ['max_input_tokens' => ['escalate' => 100]]]);
        $h->analyzer->confidence = 0.3;
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();

        $h->watcher(self::source(self::group('Alpha')))->run();

        self::assertCount(1, $h->analyzer->requests, 'the analysis only');
        self::assertStringContainsString('1 escalation(s) skipped', implode("\n", $h->sentTexts()));
    }

    public function testC1TriageTruncationSeesTheSystemPromptNotOnlyTheGroups(): void
    {
        // The groups alone are about 150 tokens; the triage prompt is about 540 more.
        $h = new Harness(['analysis' => ['max_input_tokens' => ['triage' => 600]]]);
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();

        $h->watcher(self::source(self::group('Alpha')))->run();

        self::assertSame([], $h->analyzer->triageRequests);
        self::assertStringContainsString('1 groups dropped (budget)', implode("\n", $h->sentTexts()));
    }

    public function testR5RetryAfterAFailedAnalysisDoesNotPayForTriageAgain(): void
    {
        $h = new Harness();
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $events = [...self::group('Alpha'), ...self::group('Beta')];
        $h->analyzer->analyzeFailure = new AnalyzerFailed('provider unavailable');

        $first = $h->watcher(self::source($events))->run();

        self::assertSame(RunOutcome::Failed, $first->outcome);
        self::assertCount(1, $h->analyzer->triageRequests, 'attempt 1 paid for triage');

        $h->analyzer->analyzeFailure = null;
        $second = $h->watcher(self::source($events))->run();

        self::assertSame(RunOutcome::Reported, $second->outcome);
        self::assertCount(1, $h->analyzer->triageRequests, 'attempt 2 reused it: zero triage calls');
        self::assertCount(2, $h->analyzer->requests, 'two analyses (failed, then ok) and no more');
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertSame([], $h->state->get(Keys::triage('svc', \Watcher\Watcher::windowKey($h->windowEnd()))) ?? [], 'cleared once delivered');
        self::assertEveryKeyExpires($h);
    }

    public function testR5TheCheckpointHoldsNoFindingAndOnlyLivesSixHours(): void
    {
        $h = new Harness();
        $h->watcher(self::source(self::group('Alpha')))->run();
        $h->nextWindow();
        $h->analyzer->analyzeFailure = new AnalyzerFailed('provider unavailable');

        $h->watcher(self::source(self::group('Alpha')))->run();

        $key = Keys::triage('svc', \Watcher\Watcher::windowKey($h->windowEnd()));
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertSame(6 * 3600, $h->state->ttls()[$key]);
        self::assertSame(['interesting'], array_values($h->state->get($key) ?? []));
        self::assertSame([], array_filter(array_keys($h->state->all()), static fn (string $k): bool => str_starts_with($k, Keys::REPORTED . ':') || str_starts_with($k, Keys::VERDICT . ':')), 'no verdict or reported marker from a failed run');
    }

    public function testBaselineModeDoesNotChangeTheNumberOfModelCalls(): void
    {
        $calls = [];
        foreach (['previous', 'rolling', 'weekday'] as $mode) {
            $h = new Harness(['baseline' => ['mode' => $mode]]);
            for ($d = 0; $d < 5; ++$d) {
                $h->watcher(self::source(self::group('Alpha', 5 + $d * 20)))->run();
                $h->nextWindow();
            }
            $calls[$mode] = count($h->analyzer->allRequests());
        }

        self::assertSame(1, count(array_unique($calls)), json_encode($calls) ?: '');
    }
}
