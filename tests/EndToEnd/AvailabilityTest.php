<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\Level;
use Watcher\Operate\RunOptions;
use Watcher\RunOutcome;
use Watcher\Schedule\Window;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\ProbeStateStore;
use Watcher\Watcher;

/** A4-01/03/05/06/07/08: deadline, quiet-run blindness, lock renewal, notice dedupe, delivered marker, dry-run cap. */
final class AvailabilityTest extends TestCase
{
    /** @return list<\Watcher\Source\LogEvent> */
    private static function alpha(int $n = 5): array
    {
        return Harness::events(Level::Error, 'Alpha failed in a unique way', $n, 'Alpha');
    }

    /** @param list<\Watcher\Source\LogEvent> $events */
    private static function source(array $events): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /** @return list<string> */
    private static function notices(Harness $h): array
    {
        return array_map(static fn (array $s): string => $s['message']->text, $h->notifier->sent);
    }

    private static function clockedSource(FrozenClock $clock, int $events, string $step): LogSource
    {
        return new class ($clock, $events, $step) implements LogSource {
            public bool $truncated = false;

            public function __construct(private readonly FrozenClock $clock, private readonly int $n, private readonly string $step)
            {
            }

            public function totals(Window $window, StreamSelector $selector): array
            {
                return ['error' => $this->n];
            }

            public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): \Generator
            {
                $this->truncated = false;
                foreach (Harness::events(Level::Error, 'Beta broke', $this->n, 'Beta') as $e) {
                    $this->clock->advance($this->step);
                    yield $e;
                    if ($filter->shouldStop()) {
                        $this->truncated = true;

                        return;
                    }
                }
            }

            public function lastReadTruncated(): bool
            {
                return $this->truncated;
            }

            public function versions(Window $window, StreamSelector $selector, string $label): array
            {
                return [];
            }
        };
    }

    /** @return array{Harness, string} a harness past its baseline window, and the run key of the window under test */
    private function cutHarness(): array
    {
        $h = new Harness(['schedule' => ['lock_ttl' => 900, 'run_deadline' => 10]]);
        $h->watcher(self::source(self::alpha()))->run(); // baseline
        $h->nextWindow();

        return [$h, Keys::run('svc', Watcher::windowKey($h->windowEnd()))];
    }

    private static function beta(int $n = 5): FakeLogSource
    {
        return self::source(Harness::events(Level::Error, 'Beta broke', $n, 'Beta'));
    }

    public function testTheRunDeadlineStopsReadingSkipsTheModelAndSaysSo(): void
    {
        [$h, $runKey] = $this->cutHarness();
        $partialKey = Keys::partial('svc', Watcher::windowKey($h->windowEnd()));
        $result = $h->watcher(self::clockedSource($h->clock, 100, '+4 seconds'))->run();

        self::assertSame(RunOutcome::Quiet, $result->outcome);
        self::assertSame([], $h->analyzer->allRequests(), 'no model call after the deadline');
        self::assertSame('truncated:deadline', $result->summaryData?->reason);
        $texts = implode("\n", self::notices($h));
        self::assertStringContainsString('deadline', $texts);
        self::assertLessThan(100, $result->summaryData?->stats?->eventsRead ?? 0);
        // R-3: a cut window is not final: no run record, so the next trigger can read it again.
        self::assertNull($h->state->get($runKey), 'a cut window leaves no done record');
        self::assertNotNull($h->state->get($partialKey));
    }

    public function testACutWindowIsRetriedOnceWithAFreshBudgetAndThenAnalysed(): void
    {
        [$h, $runKey] = $this->cutHarness();
        $h->watcher(self::clockedSource($h->clock, 100, '+4 seconds'))->run();

        $retry = $h->watcher(self::beta())->run();

        self::assertSame(RunOutcome::Reported, $retry->outcome, 'the retry reads the whole window and analyses it');
        self::assertNotSame([], $h->analyzer->allRequests());
        self::assertSame('reported', ($h->state->get($runKey) ?? [])['outcome'] ?? null);
        self::assertSame(RunOutcome::SkippedDone, $h->watcher(self::beta())->run()->outcome);
    }

    public function testAWindowCutTwiceIsRecordedFinalWithALoudNoticeAndNeverRetriedAgain(): void
    {
        [$h, $runKey] = $this->cutHarness();
        $h->watcher(self::clockedSource($h->clock, 100, '+4 seconds'))->run();
        $before = count($h->notifier->sent);

        $second = $h->watcher(self::clockedSource($h->clock, 100, '+4 seconds'))->run();

        self::assertSame(RunOutcome::Quiet, $second->outcome);
        self::assertSame('truncated:deadline', $second->summaryData?->reason);
        self::assertSame('truncated:deadline', ($h->state->get($runKey) ?? [])['code'] ?? null);
        self::assertCount($before + 1, $h->notifier->sent);
        self::assertStringContainsString('not analysed', self::notices($h)[$before]);

        $third = $h->watcher($src = self::beta())->run();

        self::assertSame(RunOutcome::SkippedDone, $third->outcome, 'no retry storm');
        self::assertSame([], $src->calls);
    }

    public function testAQuietRunThatWasTruncatedStillSendsOneNotice(): void
    {
        $h = new Harness(['source' => ['event_cap' => 3]]);
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();
        self::assertSame(RunOutcome::Reported, $h->watcher(self::source(self::alpha(2)))->run()->outcome);
        $h->nextWindow();
        $before = count($h->notifier->sent);

        $result = $h->watcher(self::source(self::alpha(50)))->run();

        self::assertSame(RunOutcome::Quiet, $result->outcome);
        self::assertCount($before + 1, $h->notifier->sent);
        self::assertStringContainsString('truncated', (end($h->notifier->sent) ?: throw new \LogicException())['message']->text);
        self::assertSame('truncated:cap', $result->summaryData?->reason);
    }

    public function testZeroEventsForThreeWindowsIsReported(): void
    {
        $h = new Harness();
        $texts = [];
        for ($i = 1; $i <= 3; ++$i) {
            $h->watcher(self::source([]))->run();
            $texts[$i] = implode("\n", self::notices($h));
            $h->nextWindow();
        }
        self::assertStringNotContainsString('consecutive windows', $texts[2]);
        self::assertStringContainsString('0 events read for 3 consecutive windows', $texts[3]);
    }

    public function testFailureNoticesAreCappedPerWindow(): void
    {
        $h = new Harness();
        $src = self::source(self::alpha());
        $src->failWith(new SourceUnavailable('loki 503'));
        for ($i = 0; $i < 6; ++$i) {
            self::assertSame(RunOutcome::Failed, $h->watcher($src)->run()->outcome);
        }
        self::assertCount(3, $h->notifier->sent);
    }

    public function testADigestThatWasDeliveredIsNotSentAgainWhenTheStateWriteFailed(): void
    {
        $h = new Harness();
        $probe = new ProbeStateStore(new InMemoryStateStore($h->clock));
        $h->state = $probe;
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();

        $probe->failPutPrefix = Keys::SNAP . ':';
        self::assertSame(RunOutcome::Failed, $h->watcher(self::source(self::alpha(9)))->run()->outcome);
        $sent = count($h->notifier->sent);
        self::assertGreaterThanOrEqual(1, $sent);
        $probe->failPutPrefix = null;
        $second = $h->watcher(self::source(self::alpha(9)))->run();

        self::assertSame(RunOutcome::SkippedDone, $second->outcome);
        self::assertCount($sent, $h->notifier->sent, 'the second trigger sends nothing');
    }

    public function testTheLockIsRenewedBeforeALateModelCall(): void
    {
        $h = new Harness();
        $probe = new ProbeStateStore(new InMemoryStateStore($h->clock));
        $h->state = $probe;
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();
        $slow = new class ($h->clock, $h->analyzer) implements \Watcher\Analyze\Analyzer {
            public function __construct(private readonly FrozenClock $clock, private readonly EchoAnalyzer $inner)
            {
            }

            public function triage(\Watcher\Analyze\TriageRequest $req): \Watcher\Analyze\TriageResult
            {
                $this->clock->advance('+500 seconds'); // more than half of lock_ttl (900) gone

                return $this->inner->triage($req);
            }

            public function analyze(\Watcher\Analyze\AnalysisRequest $req): \Watcher\Analyze\AnalysisResult
            {
                return $this->inner->analyze($req);
            }
        };
        $w = new Watcher(\Watcher\Config\WatchConfig::fromArray($h->config()), self::source(self::alpha(9)), $probe, $slow, $h->notifier, $h->clock);

        $w->run();

        self::assertNotEmpty($probe->renewed);
        self::assertStringStartsWith('lock:', $probe->renewed[0]);
    }

    public function testALostLockStopsTheRunAtTheNextCheckpointInsteadOfWarning(): void
    {
        $h = new Harness();
        $probe = new ProbeStateStore(new InMemoryStateStore($h->clock));
        $h->state = $probe;
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();
        $probe->loseLock = true;
        $analyses = new \stdClass();
        $analyses->n = 0;
        $slow = new class ($h->clock, $h->analyzer, $analyses) implements \Watcher\Analyze\Analyzer {
            public function __construct(private readonly FrozenClock $clock, private readonly EchoAnalyzer $inner, private readonly \stdClass $count)
            {
            }

            public function triage(\Watcher\Analyze\TriageRequest $req): \Watcher\Analyze\TriageResult
            {
                $this->clock->advance('+500 seconds'); // more than half of lock_ttl gone: the next checkpoint renews

                return $this->inner->triage($req);
            }

            public function analyze(\Watcher\Analyze\AnalysisRequest $req): \Watcher\Analyze\AnalysisResult
            {
                $this->count->n++;

                return $this->inner->analyze($req);
            }
        };
        $before = count($h->notifier->sent);

        $r = (new Watcher(\Watcher\Config\WatchConfig::fromArray($h->config()), self::source(self::alpha(9)), $probe, $slow, $h->notifier, $h->clock))->run();

        self::assertSame(RunOutcome::Failed, $r->outcome);
        self::assertSame('lock_lost', $r->summaryData?->reason);
        self::assertSame(0, $analyses->n, 'no model call after the lock was lost');
        self::assertCount($before, $h->notifier->sent, 'no digest from a run that no longer owns the window');
    }

    public function testADryRunRefusesToStartWhenTheRealDailyCapIsAlreadyReached(): void
    {
        $h = new Harness(['analysis' => ['daily_budget_usd' => 1.0]]);
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();
        $cfg = \Watcher\Config\WatchConfig::fromArray($h->config());
        (new Spend($h->state, $h->clock, new \DateTimeZone('UTC'), 'svc'))->add(new Usage('m-a', 1, 1), 1.0);

        $out = '';
        $result = $h->watcher(self::source(self::alpha(9)), new RunOptions(dryRun: true), static function (string $s) use (&$out): void {
            $out .= $s;
        })->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame([], $h->analyzer->allRequests());
        unset($cfg);
    }
}
