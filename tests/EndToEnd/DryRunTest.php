<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Level;
use Watcher\Operate\RunOptions;
use Watcher\RunOutcome;
use Watcher\Source\LogSource;
use Watcher\Source\SourceUnavailable;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\RecordingStateStore;

/** US3: --dry-run runs the whole pipeline and changes nothing; --estimate makes no model call (spec 002, T016). */
final class DryRunTest extends TestCase
{
    private const AT_SIX = ['schedule' => ['run_at' => '06:00']];

    private string $out = '';

    private static function source(int $n = 9): FakeLogSource
    {
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', $n, 'Alpha');

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /**
     * A harness whose first window is baselined, standing at the second window with a spy on the store.
     *
     * @param array<string, mixed> $patch
     *
     * @return array{0: Harness, 1: RecordingStateStore}
     */
    private function ready(array $patch = []): array
    {
        $h = new Harness(array_replace_recursive(self::AT_SIX, $patch));
        $h->watcher(self::source(5))->run();
        $h->nextWindow();
        $h->state = $spy = new RecordingStateStore($h->state);

        return [$h, $spy];
    }

    private function dry(Harness $h, LogSource $source, bool $estimate = false): \Watcher\RunResult
    {
        $this->out = '';

        return $h->watcher($source, RunOptions::dryRun($estimate), function (string $s): void {
            $this->out .= $s;
        })->run();
    }

    public function testADryRunPrintsTheSameDigestTheRealRunSendsAndWritesNothing(): void
    {
        [$h, $spy] = $this->ready();
        $sentBefore = count($h->notifier->sent);

        $dry = $this->dry($h, self::source());

        self::assertSame(RunOutcome::Reported, $dry->outcome);
        self::assertSame([], $spy->writes(), 'a dry run must not write, lock or count anything');
        self::assertCount($sentBefore, $h->notifier->sent, 'nothing is posted');
        self::assertStringContainsString('DRY RUN', $this->out);
        self::assertNotSame([], $h->analyzer->triageRequests, 'model calls are real in a dry run');

        $real = $h->watcher(self::source())->run();
        self::assertSame(RunOutcome::Reported, $real->outcome);
        $digest = $h->notifier->last()['message']->text;
        self::assertStringContainsString($digest, $this->out);
    }

    public function testADryRunIgnoresTheAlreadyDoneRecordAndSaysSo(): void
    {
        [$h] = $this->ready();
        self::assertSame(RunOutcome::Reported, $h->watcher(self::source())->run()->outcome);

        $dry = $this->dry($h, self::source());

        // Quiet, not skipped_done: the real run's "reported" markers still filter the groups, only the run record is ignored.
        self::assertNotSame(RunOutcome::SkippedDone, $dry->outcome);
        self::assertStringContainsString('already reported', $this->out);
    }

    public function testADryRunOutsideTheRunHourStillRunsTheMostRecentWindow(): void
    {
        [$h, $spy] = $this->ready();
        $h->clock->advance('+3 hours 30 minutes'); // 09:30, run_at is 06:00

        $dry = $this->dry($h, self::source());

        self::assertSame(RunOutcome::Reported, $dry->outcome);
        self::assertSame([], $spy->writes());
        self::assertSame('2026-10-09T06:00:00+00:00', $dry->summaryData?->windowEnd?->format(DATE_ATOM));
    }

    public function testADryRunBeforeTodaysRunAtUsesYesterdaysWindow(): void
    {
        [$h] = $this->ready();
        $h->clock->set(new DateTimeImmutable('2026-10-09T05:00:00+00:00'));

        $dry = $this->dry($h, self::source());

        self::assertSame('2026-10-08T06:00:00+00:00', $dry->summaryData?->windowEnd?->format(DATE_ATOM));
    }

    public function testNothingIsWrittenForQuietBaselinedFailedAndWeeklyRuns(): void
    {
        // quiet
        [$h, $spy] = $this->ready(['heartbeat' => ['weekly_summary' => true]]);
        self::assertSame(RunOutcome::Quiet, $this->dry($h, new FakeLogSource([], []))->outcome);
        self::assertSame([], $spy->writes());

        // baselined: nothing in the store yet
        $fresh = new Harness(self::AT_SIX);
        $fresh->state = $spy = new RecordingStateStore($fresh->state);
        self::assertSame(RunOutcome::Baselined, $this->dry($fresh, self::source())->outcome);
        self::assertSame([], $spy->writes());
        self::assertCount(0, $fresh->notifier->sent);

        // failed (analyzer): no lock left, no heartbeat
        [$h, $spy] = $this->ready();
        $h->analyzer->failure = new AnalyzerFailed('boom');
        self::assertSame(RunOutcome::Failed, $this->dry($h, self::source())->outcome);
        self::assertSame([], $spy->writes());
        self::assertNull($h->state->get($h->lockKey()));

        // failed (source)
        [$h, $spy] = $this->ready();
        $src = self::source();
        $src->failWith(new SourceUnavailable('HTTP 503'));
        self::assertSame(RunOutcome::Failed, $this->dry($h, $src)->outcome);
        self::assertSame([], $spy->writes());
    }

    public function testEstimateMakesNoModelCallAndPrintsTheStepsInsteadOfADigest(): void
    {
        [$h, $spy] = $this->ready();
        $sentBefore = count($h->notifier->sent);

        $dry = $this->dry($h, self::source(), true);

        self::assertNotSame(RunOutcome::Failed, $dry->outcome);
        self::assertSame([], $h->analyzer->triageRequests);
        self::assertSame([], $h->analyzer->requests);
        self::assertSame([], $spy->writes());
        self::assertCount($sentBefore, $h->notifier->sent);
        self::assertStringContainsString('--estimate', $this->out);
        self::assertMatchesRegularExpression('/triage\s+1\n/', $this->out);
    }

}
