<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Level;
use Watcher\Operate\Heartbeat;
use Watcher\RunOutcome;
use Watcher\Source\LogSource;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\RecordingStateStore;
use Watcher\Tests\Fakes\SpyLogger;

/** US1: Watcher::run() leaves a liveness record (spec 002, T004, FR-005). */
final class HeartbeatTest extends TestCase
{
    private const AT_SIX = ['schedule' => ['run_at' => '06:00']];

    private static function source(int $n = 5): LogSource
    {
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', $n, 'Alpha');

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    private static function quietSource(): LogSource
    {
        return new FakeLogSource([], []);
    }

    /** @return array<mixed> */
    private static function hb(Harness $h): array
    {
        return $h->state->get(Keys::heartbeat('svc')) ?? [];
    }

    public function testBaselinedThenReportedThenQuietRunsWriteTheRightRecords(): void
    {
        $h = new Harness(self::AT_SIX);
        $h->watcher(self::source())->run();
        self::assertSame('baselined', self::hb($h)['outcome']);
        self::assertNotNull(self::hb($h)['lastOkAt']);

        $h->nextWindow();
        $h->watcher(self::source(9))->run();
        self::assertSame('reported', self::hb($h)['outcome']);
        self::assertSame($h->clock->now()->format(DATE_ATOM), self::hb($h)['finishedAt']);

        $h->nextWindow();
        $h->watcher(self::quietSource())->run();
        self::assertSame('quiet', self::hb($h)['outcome']);
    }

    public function testFailedRunKeepsTheLastOkInstantAndStoresAShortCode(): void
    {
        $h = new Harness(self::AT_SIX);
        $h->watcher(self::source())->run();
        $okAt = self::hb($h)['lastOkAt'];
        $h->nextWindow();
        $h->analyzer->failure = new AnalyzerFailed('bad output sk-ant-api03-SECRETSECRETSECRETSECRET');

        $result = $h->watcher(self::source(9))->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame('failed', self::hb($h)['outcome']);
        self::assertSame($okAt, self::hb($h)['lastOkAt']);
        self::assertSame('analysis_failed', self::hb($h)['reason']);
    }

    public function testDoneAndLockedSkipsWriteOnlyTheTriggerRecord(): void
    {
        $h = new Harness(self::AT_SIX);
        $h->watcher(self::source())->run();
        $before = self::hb($h);
        $h->clock->advance('+10 minutes');

        $second = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::SkippedDone, $second->outcome);
        self::assertSame($before, self::hb($h));
        self::assertSame($h->clock->now()->format(DATE_ATOM), ($h->state->get(Keys::trigger('svc')) ?? [])['at'] ?? null);

        $h2 = new Harness(self::AT_SIX);
        $h2->state->acquire($h2->lockKey(), 'someone', 900);
        $locked = $h2->watcher(self::source())->run();
        self::assertSame(RunOutcome::SkippedLocked, $locked->outcome);
        self::assertNull($h2->state->get(Keys::heartbeat('svc')));
        self::assertNotNull($h2->state->get(Keys::trigger('svc')));
    }

    public function testANotDueTriggerTouchesNoStateAtAll(): void
    {
        $h = new Harness(self::AT_SIX);
        $h->state = $spy = new RecordingStateStore($h->state);
        $h->clock->advance('+3 hours'); // outside the run_at hour

        $result = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::SkippedNotDue, $result->outcome);
        self::assertSame([], $spy->calls);
    }

    public function testAHeartbeatWriteFailureChangesNeitherOutcomeNorExitCode(): void
    {
        $h = new Harness(self::AT_SIX);
        $h->state = $spy = new RecordingStateStore($h->state);
        $spy->failWritesTo('hb');
        $h->hostLog = $log = new SpyLogger();

        $result = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::Baselined, $result->outcome);
        self::assertSame(0, $result->exitCode());
        self::assertNotSame([], $log->records);
        self::assertNull($h->state->get(Keys::heartbeat('svc')));
    }

    public function testTheHeartbeatGoesStaleOnceTheClockPassesTheLimit(): void
    {
        $h = new Harness(self::AT_SIX);
        $h->watcher(self::source())->run();
        $hb = new Heartbeat($h->state, $h->clock, 'svc', new DateTimeZone('UTC'), 2592000);
        $limit = 129600; // 1.5 x PT24H

        self::assertSame('ok', $hb->status($limit)->state);
        $h->clock->advance('+37 hours');
        self::assertSame('stale', $hb->status($limit)->state);
    }

    public function testWeeklySummaryIsSentOnceByTheFirstDueRunOfTheNextWeek(): void
    {
        $h = new Harness(['schedule' => ['run_at' => '06:00'], 'heartbeat' => ['weekly_summary' => true]]);
        $h->clock->set(new DateTimeImmutable('2026-10-08T06:00:00+00:00')); // 2026-W41
        $h->watcher(self::source())->run();
        self::assertSame([], self::weeklyTexts($h));

        $h->clock->set(new DateTimeImmutable('2026-10-15T06:00:00+00:00')); // 2026-W42
        $h->watcher(self::source())->run();
        self::assertCount(1, self::weeklyTexts($h));
        self::assertStringContainsString('week 2026-W41', implode(' ', self::weeklyTexts($h)));

        $h->clock->advance('+10 minutes'); // same week, second trigger (done): still only one
        $h->watcher(self::source())->run();
        self::assertCount(1, self::weeklyTexts($h));
    }

    public function testNoWeeklySummaryForAWeekWithoutADueRunOrWhenSwitchedOff(): void
    {
        $h = new Harness(['schedule' => ['run_at' => '06:00'], 'heartbeat' => ['weekly_summary' => true]]);
        $h->clock->set(new DateTimeImmutable('2026-10-08T06:00:00+00:00')); // W41
        $h->watcher(self::source())->run();
        $h->clock->set(new DateTimeImmutable('2026-10-22T06:00:00+00:00')); // W43: W42 had no run
        $h->watcher(self::source())->run();
        self::assertSame([], self::weeklyTexts($h));

        $off = new Harness(self::AT_SIX);
        $off->clock->set(new DateTimeImmutable('2026-10-08T06:00:00+00:00'));
        $off->watcher(self::source())->run();
        $off->clock->set(new DateTimeImmutable('2026-10-15T06:00:00+00:00'));
        $off->watcher(self::source())->run();
        self::assertSame([], self::weeklyTexts($off));
    }

    /** @return list<string> */
    private static function weeklyTexts(Harness $h): array
    {
        return array_values(array_filter($h->sentTexts(), static fn (string $t): bool => str_contains($t, ' — week 20')));
    }
}
