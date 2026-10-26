<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\NoticeRenderer;
use Watcher\Operate\Heartbeat;
use Watcher\RunOutcome;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FailingStateStore;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\SpyLogger;

final class HeartbeatTest extends TestCase
{
    private const RETENTION = 2592000;
    private const LIMIT = 129600;

    private FrozenClock $clock;
    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-21T06:00:40+00:00')); // Wednesday, 2026-W43
        $this->store = new InMemoryStateStore($this->clock);
    }

    /** @return array<mixed> */
    private function rec(string $key): array
    {
        $r = $this->store->get($key);
        self::assertNotNull($r, $key);

        return $r;
    }

    private function hb(?FailingStateStore $failing = null, ?SpyLogger $log = null): Heartbeat
    {
        return new Heartbeat($failing ?? $this->store, $this->clock, 'svc', new DateTimeZone('UTC'), self::RETENTION, $log);
    }

    private function finish(Heartbeat $hb, RunOutcome $o, ?string $reason = null, float $cost = 0.0): void
    {
        $hb->recordRun(
            $o,
            new DateTimeImmutable('2026-10-20T06:00:00+00:00'),
            new DateTimeImmutable('2026-10-21T06:00:00+00:00'),
            $this->clock->now()->modify('-38 seconds'),
            $cost,
            $reason,
        );
    }

    public function testRecordRunWritesTheRecord(): void
    {
        $this->finish($this->hb(), RunOutcome::Quiet, null, 0.0123);

        $r = $this->rec(Keys::heartbeat('svc'));
        self::assertSame(1, $r['v']);
        self::assertSame('svc', $r['service']);
        self::assertSame('quiet', $r['outcome']);
        self::assertNull($r['reason']);
        self::assertSame('2026-10-21T06:00:40+00:00', $r['finishedAt']);
        self::assertSame('2026-10-20T06:00:00+00:00', $r['windowStart']);
        self::assertSame('2026-10-21T06:00:00+00:00', $r['windowEnd']);
        self::assertSame(38, $r['durationSeconds']);
        self::assertSame(0.0123, $r['costUsd']);
        self::assertSame('2026-10-21T06:00:40+00:00', $r['lastOkAt']);
        self::assertSame(3 * self::RETENTION, $this->store->ttls()[Keys::heartbeat('svc')]);
    }

    public function testFailedRunCarriesLastOkForwardAndKeepsReasonShortAndMasked(): void
    {
        $hb = $this->hb();
        $this->finish($hb, RunOutcome::Reported);
        $this->clock->advance('+1 day');
        $reason = "boom \"x\"\n at https://user:pw@host/path?k=1 " . str_repeat('a', 300);
        $this->finish($hb, RunOutcome::Failed, $reason);

        $r = $this->rec(Keys::heartbeat('svc'));
        self::assertSame('failed', $r['outcome']);
        self::assertSame('2026-10-21T06:00:40+00:00', $r['lastOkAt']);
        self::assertLessThanOrEqual(200, mb_strlen($r['reason']));
        self::assertStringNotContainsString("\n", $r['reason']);
        self::assertStringNotContainsString('"', $r['reason']);
        self::assertStringNotContainsString('pw@host', $r['reason']);
    }

    public function testFailedRunWithoutPriorOkHasNullLastOk(): void
    {
        $this->finish($this->hb(), RunOutcome::Failed, 'down');

        self::assertNull($this->rec(Keys::heartbeat('svc'))['lastOkAt']);
    }

    public function testConfiguredSecretsAreMaskedInTheReason(): void
    {
        $hb = new Heartbeat($this->store, $this->clock, 'svc', new DateTimeZone('UTC'), self::RETENTION, null, ['sk-topsecret-1']);
        $this->finish($hb, RunOutcome::Failed, 'auth failed for sk-topsecret-1');

        self::assertStringNotContainsString('sk-topsecret-1', $this->rec(Keys::heartbeat('svc'))['reason']);
    }

    public function testSkippedAndInvalidOutcomesNeverTouchTheRunRecord(): void
    {
        $hb = $this->hb();
        foreach ([RunOutcome::SkippedNotDue, RunOutcome::SkippedDone, RunOutcome::SkippedLocked, RunOutcome::InvalidConfig] as $o) {
            $this->finish($hb, $o);
        }

        self::assertSame([], $this->store->all());
    }

    public function testOnlyDueButSkippedTriggersAreRecorded(): void
    {
        $hb = $this->hb();
        $hb->recordSkip(RunOutcome::SkippedNotDue);
        self::assertSame([], $this->store->all());

        $hb->recordSkip(RunOutcome::SkippedDone);
        self::assertSame(['at' => '2026-10-21T06:00:40+00:00'], $this->rec(Keys::trigger('svc')));
        self::assertSame(3 * self::RETENTION, $this->store->ttls()[Keys::trigger('svc')]);

        $this->clock->advance('+1 hour');
        $hb->recordSkip(RunOutcome::SkippedLocked);
        self::assertSame('2026-10-21T07:00:40+00:00', $this->rec(Keys::trigger('svc'))['at']);
        self::assertNull($this->store->get(Keys::heartbeat('svc')));
    }

    public function testRecordSkipIgnoresRealOutcomes(): void
    {
        $this->hb()->recordSkip(RunOutcome::Quiet);

        self::assertSame([], $this->store->all());
    }

    public function testStatusUnknownWhenNoRecord(): void
    {
        $s = $this->hb()->status(self::LIMIT);

        self::assertSame('unknown', $s->state);
        self::assertFalse($s->ok());
        self::assertNull($s->record);
        self::assertNull($s->ageSeconds);
        self::assertSame(self::LIMIT, $s->limitSeconds);
    }

    public function testStatusUnknownWhenStoreUnreadable(): void
    {
        $failing = new FailingStateStore($this->store);
        $failing->failOn('get');

        self::assertSame('unknown', $this->hb($failing)->status(self::LIMIT)->state);
    }

    public function testAnUnreadPodEventsClusterMakesAnOtherwiseOkStatusDegradedWithANonZeroExit(): void
    {
        $hb = $this->hb();
        foreach (['unavailable', 'stale'] as $events) {
            $hb->recordRun(RunOutcome::Quiet, new DateTimeImmutable('2026-10-20T06:00:00+00:00'), new DateTimeImmutable('2026-10-21T06:00:00+00:00'), $this->clock->now()->modify('-38 seconds'), 0.0, 'k8s_ingest_failed:unauthorised', $events);

            $s = $hb->status(self::LIMIT);

            self::assertSame('degraded', $s->state, $events);
            self::assertFalse($s->ok());
            self::assertSame(1, $s->exitCode());
            self::assertStringContainsString("events:         $events", $s->text('svc'));
        }
        $hb->recordRun(RunOutcome::Quiet, new DateTimeImmutable('2026-10-20T06:00:00+00:00'), new DateTimeImmutable('2026-10-21T06:00:00+00:00'), $this->clock->now()->modify('-38 seconds'), 0.0, null, 'ok');
        self::assertSame('ok', $hb->status(self::LIMIT)->state, 'a later successful read clears it');
    }

    public function testStatusOkThenStale(): void
    {
        $hb = $this->hb();
        $this->finish($hb, RunOutcome::Quiet);
        $this->store->put(Keys::trigger('svc'), ['at' => '2026-10-21T07:00:00+00:00'], 999999);

        $this->clock->advance('+3 hours');
        $s = $hb->status(self::LIMIT);
        self::assertSame('ok', $s->state);
        self::assertTrue($s->ok());
        self::assertSame(10800, $s->ageSeconds);
        self::assertSame('2026-10-21T09:00:40+00:00', $s->now->format('c'));
        self::assertSame('2026-10-21T06:00:40+00:00', $s->comparedWith?->format('c'));
        self::assertSame('2026-10-21T07:00:00+00:00', $s->lastTriggerAt?->format('c'));
        self::assertSame('quiet', $s->record['outcome'] ?? null);

        $this->clock->advance('+' . self::LIMIT . ' seconds');
        $s = $hb->status(self::LIMIT);
        self::assertSame('stale', $s->state);
        self::assertFalse($s->ok());
    }

    public function testAgeExactlyAtLimitIsStillOk(): void
    {
        $hb = $this->hb();
        $this->finish($hb, RunOutcome::Reported);
        $this->clock->advance('+' . self::LIMIT . ' seconds');

        self::assertSame('ok', $hb->status(self::LIMIT)->state);
    }

    public function testStatusFailedEvenWhenLastOkIsRecent(): void
    {
        $hb = $this->hb();
        $this->finish($hb, RunOutcome::Reported);
        $this->clock->advance('+1 hour');
        $this->finish($hb, RunOutcome::Failed, 'down');

        $s = $hb->status(self::LIMIT);
        self::assertSame('failed', $s->state);
        self::assertFalse($s->ok());
        self::assertSame(3600, $s->ageSeconds);
    }

    public function testWriteFailuresAreSwallowedAndLoggedWithoutTheMessage(): void
    {
        $failing = new FailingStateStore($this->store);
        $failing->failOn('put');
        $failing->failOn('increment');
        $log = new SpyLogger();
        $hb = $this->hb($failing, $log);

        $this->finish($hb, RunOutcome::Reported, null, 1.0);
        $hb->recordSkip(RunOutcome::SkippedDone);

        self::assertNotSame([], $log->records);
        self::assertStringNotContainsString('hunter2', json_encode($log->records, JSON_THROW_ON_ERROR));
    }

    public function testWriteFailuresWithoutALoggerAreSilent(): void
    {
        $failing = new FailingStateStore($this->store);
        $failing->failOn('put');
        $failing->failOn('get');
        $failing->failOn('increment');
        $hb = $this->hb($failing);

        $this->finish($hb, RunOutcome::Quiet);
        $hb->recordSkip(RunOutcome::SkippedLocked);

        $this->expectNotToPerformAssertions();
    }

    public function testEveryWrittenKeyHasAnExpiry(): void
    {
        $hb = $this->hb();
        $this->finish($hb, RunOutcome::Reported, null, 0.5);
        $hb->recordSkip(RunOutcome::SkippedDone);

        $ttls = $this->store->ttls();
        self::assertNotSame([], $ttls);
        foreach ($ttls as $key => $ttl) {
            self::assertGreaterThan(0, $ttl, $key);
        }
    }

    public function testWeeklyCountersUseTheIsoWeekInTheServiceTimezone(): void
    {
        // 2026-10-25 23:30 UTC is Monday 2026-10-26 in Kyiv: ISO week 44, not 43.
        $this->clock->set(new DateTimeImmutable('2026-10-25T23:30:00+00:00'));
        $hb = new Heartbeat($this->store, $this->clock, 'svc', new DateTimeZone('Europe/Kyiv'), self::RETENTION);
        $this->finish($hb, RunOutcome::Quiet, null, 0.25);

        self::assertSame(1.0, $this->rec(Keys::week('svc', '2026-W44', 'quiet'))['total']);
        self::assertSame(0.25, $this->rec(Keys::week('svc', '2026-W44', 'cost'))['total']);
        self::assertSame(1209600, $this->store->ttls()[Keys::week('svc', '2026-W44', 'quiet')]);
    }

    public function testWeeklySkipCountersCountDoneAndLockedOnly(): void
    {
        $hb = $this->hb();
        $hb->recordSkip(RunOutcome::SkippedDone);
        $hb->recordSkip(RunOutcome::SkippedLocked);
        $hb->recordSkip(RunOutcome::SkippedNotDue);

        self::assertSame(1.0, $this->rec(Keys::week('svc', '2026-W43', 'skipped_done'))['total']);
        self::assertSame(1.0, $this->rec(Keys::week('svc', '2026-W43', 'skipped_locked'))['total']);
        self::assertNull($this->store->get(Keys::week('svc', '2026-W43', 'skipped_not_due')));
    }

    /** One week of runs (W43), then the first run of W44. */
    private function aWeekOfRuns(Heartbeat $hb): void
    {
        $this->finish($hb, RunOutcome::Reported, null, 0.10);
        $this->finish($hb, RunOutcome::Quiet, null, 0.05);
        $this->finish($hb, RunOutcome::Quiet, null, 0.05);
        $this->finish($hb, RunOutcome::Failed, 'down', 0.0);
        $hb->recordSkip(RunOutcome::SkippedDone);
        $this->clock->set(new DateTimeImmutable('2026-10-26T06:00:10+00:00'));
    }

    public function testWeeklySummaryIsSentOnceForThePreviousWeek(): void
    {
        $hb = $this->hb();
        $this->aWeekOfRuns($hb);
        $notifier = new FakeNotifier();
        $to = new Destination('https://hooks.example/x');

        self::assertTrue($hb->sendWeeklySummary($notifier, $to, new NoticeRenderer(), 'stg'));
        self::assertFalse($hb->sendWeeklySummary($notifier, $to, new NoticeRenderer(), 'stg'));

        self::assertCount(1, $notifier->sent);
        $text = $notifier->sent[0]['message']->text;
        self::assertStringContainsString('svc', $text);
        self::assertStringContainsString('2026-W43', $text);
        self::assertStringContainsString('reported 1', $text);
        self::assertStringContainsString('quiet 2', $text);
        self::assertStringContainsString('failed 1', $text);
        self::assertStringContainsString('skipped 1', $text);
        self::assertStringContainsString('$0.2000', $text);
        self::assertSame(1209600, $this->store->ttls()[Keys::weekSent('svc', '2026-W43')]);
    }

    public function testNoWeeklySummaryInTheWeekItselfOrWhenTheWeekHadNoRun(): void
    {
        $hb = $this->hb();
        $notifier = new FakeNotifier();
        $to = new Destination('https://hooks.example/x');

        $this->finish($hb, RunOutcome::Quiet);
        self::assertFalse($hb->sendWeeklySummary($notifier, $to, new NoticeRenderer(), 'stg')); // previous week (W42) is empty

        $this->clock->set(new DateTimeImmutable('2026-11-09T06:00:00+00:00')); // W46: W45 is empty
        self::assertFalse($hb->sendWeeklySummary($notifier, $to, new NoticeRenderer(), 'stg'));
        self::assertSame([], $notifier->sent);
    }

    public function testSkipsAloneDoNotMakeAWeekWorthReporting(): void
    {
        $hb = $this->hb();
        $hb->recordSkip(RunOutcome::SkippedLocked);
        $this->clock->set(new DateTimeImmutable('2026-10-26T06:00:10+00:00'));
        $notifier = new FakeNotifier();

        self::assertFalse($hb->sendWeeklySummary($notifier, new Destination('https://hooks.example/x'), new NoticeRenderer(), 'stg'));
    }

    public function testFailedWeeklySendGivesTheMarkerBackSoTheNextRunRetries(): void
    {
        $hb = $this->hb();
        $this->aWeekOfRuns($hb);
        $notifier = new FakeNotifier();
        $notifier->failOnce(new DeliveryFailed('slack down'));
        $log = new SpyLogger();
        $hb = $this->hb(null, $log);
        $to = new Destination('https://hooks.example/x');

        self::assertFalse($hb->sendWeeklySummary($notifier, $to, new NoticeRenderer(), 'stg'));
        self::assertNotSame([], $log->records);
        self::assertTrue($hb->sendWeeklySummary($notifier, $to, new NoticeRenderer(), 'stg'));
        self::assertCount(1, $notifier->sent);
    }

    public function testWeeklyStateFailureIsSwallowed(): void
    {
        $failing = new FailingStateStore($this->store);
        $failing->failOn('get');
        $failing->failOn('acquire');
        $this->clock->set(new DateTimeImmutable('2026-10-26T06:00:10+00:00'));

        self::assertFalse($this->hb($failing)->sendWeeklySummary(new FakeNotifier(), new Destination('https://hooks.example/x'), new NoticeRenderer(), 'stg'));
    }
}
