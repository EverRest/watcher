<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunOptions;
use Watcher\Operate\RunSummaryData;
use Watcher\RunOutcome;
use Watcher\RunResult;
use Watcher\Source\LineParser;
use Watcher\Source\LogSource;
use Watcher\Source\SourceUnavailable;
use Watcher\State\Keys;
use Watcher\State\StateStore;
use Watcher\State\UnreadableCounter;
use Watcher\Tests\Fakes\FailingStateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** US4: every outcome carries counts and a short reason code for the JSON line (spec 002, T020/T022). */
final class RunSummaryTest extends TestCase
{
    private const AT_SIX = ['schedule' => ['run_at' => '06:00']];

    private static function source(int $n = 9): FakeLogSource
    {
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', $n, 'Alpha');

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    private static function data(RunResult $r): RunSummaryData
    {
        self::assertNotNull($r->summaryData);

        return $r->summaryData;
    }

    private static function ready(): Harness
    {
        $h = new Harness(self::AT_SIX);
        $h->watcher(self::source(5))->run();
        $h->nextWindow();

        return $h;
    }

    public function testAReportedRunFillsTheCounts(): void
    {
        $h = self::ready();

        $r = $h->watcher(self::source())->run();

        $d = self::data($r);
        self::assertSame(RunOutcome::Reported, $d->outcome);
        self::assertSame('svc', $d->service);
        self::assertSame('2026-10-08T06:00:00+00:00', $d->windowStart?->format(DATE_ATOM));
        self::assertSame('2026-10-09T06:00:00+00:00', $d->windowEnd?->format(DATE_ATOM));
        $s = $d->stats;
        self::assertNotNull($s);
        self::assertSame(9, $s->eventsRead);
        self::assertSame(1, $s->groups);
        self::assertSame(1, $s->candidates);
        self::assertSame(1, $s->findings);
        self::assertSame(1, $s->calls['triage']);
        self::assertGreaterThanOrEqual(1, $s->calls['analyze']);
        self::assertSame($r->usage?->inputTokens, $s->tokensIn);
        self::assertEqualsWithDelta($r->usage?->costUsd, $s->costUsd, 1e-9);
        self::assertNotNull($s->durationMs);
        self::assertGreaterThan(0.0, $d->todayUsd);
        self::assertFalse($d->dryRun);
        self::assertNull($d->reason);
        self::assertStringContainsString('"outcome":"reported"', (new JsonSummary())->render($d));
    }

    public function testBaselinedAndQuietRunsHaveSummariesWithZeroModelCalls(): void
    {
        $h = new Harness(self::AT_SIX);
        $b = $h->watcher(self::source(5))->run();
        self::assertSame(RunOutcome::Baselined, self::data($b)->outcome);
        self::assertSame(5, self::data($b)->stats?->eventsRead);
        self::assertSame(['triage' => 0, 'analyze' => 0, 'escalate' => 0], self::data($b)->stats->calls);

        $h->nextWindow();
        $q = $h->watcher(new FakeLogSource([], []))->run();
        self::assertSame(RunOutcome::Quiet, self::data($q)->outcome);
        self::assertSame(0, self::data($q)->stats?->candidates);
    }

    public function testSkipsHaveASummaryWithoutStats(): void
    {
        $h = new Harness(self::AT_SIX);
        $h->watcher(self::source(5))->run();
        $done = $h->watcher(self::source(5))->run();
        self::assertSame(RunOutcome::SkippedDone, self::data($done)->outcome);
        self::assertNull(self::data($done)->stats);
        self::assertNotNull(self::data($done)->windowEnd);

        $h->clock->advance('+3 hours');
        $notDue = $h->watcher(self::source(5))->run();
        self::assertSame(RunOutcome::SkippedNotDue, self::data($notDue)->outcome);
        self::assertSame('svc', self::data($notDue)->service);
        self::assertNull(self::data($notDue)->windowEnd);
    }

    /** @return iterable<string, array{0: callable(Harness): LogSource, 1: string}> */
    public static function failures(): iterable
    {
        yield 'source' => [static function (Harness $h): LogSource {
            $s = self::source();
            $s->failWith(new SourceUnavailable('HTTP 401 https://loki.invalid/?api_key=sk-ant-api03-LEAKLEAKLEAKLEAKLEAKLEAK'));

            return $s;
        }, 'source_unavailable'];
        yield 'analysis' => [static function (Harness $h): LogSource {
            $h->analyzer->failure = new AnalyzerFailed('output echoed anna.kowalski@example.org');

            return self::source();
        }, 'analysis_failed'];
        yield 'delivery' => [static function (Harness $h): LogSource {
            $h->notifier->failWith(new DeliveryFailed('HTTP 404 https://hooks.invalid/x'));

            return self::source();
        }, 'delivery_failed'];
        yield 'internal' => [static function (Harness $h): LogSource {
            $f = new FailingStateStore($h->state);
            $f->failOn('get');
            $h->state = $f;

            return self::source();
        }, 'internal_error'];
    }

    /** @param callable(Harness): LogSource $make */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testEveryFailureCarriesAShortCodeThatPassesTheSanitiser(callable $make, string $code): void
    {
        $h = self::ready();
        $source = $make($h);

        $r = $h->watcher($source)->run();

        self::assertSame(RunOutcome::Failed, $r->outcome);
        self::assertSame($code, self::data($r)->reason);
        $line = (new JsonSummary())->render(self::data($r));
        self::assertStringContainsString('"reason":"' . $code . '"', $line);
        self::assertNotNull(self::data($r)->stats);
        // The human text stays as it was.
        self::assertNotSame($code, $r->failureReason);
    }

    public function testInvalidConfigHasASummaryToo(): void
    {
        $r = RunResult::invalidConfig('state.driver: unknown');

        self::assertSame(RunOutcome::InvalidConfig, self::data($r)->outcome);
        self::assertNull(self::data($r)->stats);
        self::assertNull(self::data($r)->service);
        self::assertStringContainsString('"outcome":"invalid_config"', (new JsonSummary())->render(self::data($r)));
    }

    public function testSummaryAndHeartbeatOfACorpusRunHoldNoSeededValue(): void
    {
        $dir = __DIR__ . '/../Fixtures/privacy';
        /** @var list<string> $seeded */
        $seeded = require $dir . '/seeded-values.php';
        $parser = new LineParser();
        $events = [];
        foreach (file($dir . '/corpus.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = $parser->parse($line);
        }
        $h = new Harness(self::AT_SIX);
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->nextWindow();
        $reported = $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->nextWindow();
        $failing = new FakeLogSource(Harness::totalsOf($events), $events);
        $failing->failWith(new SourceUnavailable(implode(' ', $seeded)));
        $failed = $h->watcher($failing)->run();

        self::assertSame(RunOutcome::Reported, $reported->outcome);
        self::assertSame(RunOutcome::Failed, $failed->outcome);
        $blobs = [
            (new JsonSummary())->render(self::data($reported)),
            (new JsonSummary())->render(self::data($failed)),
        ];
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $blobs[] = json_encode($h->state->get(Keys::heartbeat('svc')), JSON_THROW_ON_ERROR);
        $blobs[] = json_encode($h->state->get(Keys::trigger('svc')), JSON_THROW_ON_ERROR);
        foreach ($blobs as $blob) {
            foreach ($seeded as $value) {
                self::assertStringNotContainsString($value, $blob);
                self::assertStringNotContainsString(substr(json_encode($value, JSON_THROW_ON_ERROR), 1, -1), $blob);
            }
        }
        self::assertStringContainsString('source_unavailable', $blobs[1]);
    }

    private static function withUnreadable(StateStore $inner): StateStore
    {
        return new class ($inner) implements StateStore, UnreadableCounter {
            public function __construct(private readonly StateStore $inner)
            {
            }

            public function unreadableEntries(): int
            {
                return 3;
            }

            public function get(string $key): ?array
            {
                return $this->inner->get($key);
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
                $this->inner->put($key, $value, $ttlSeconds);
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                return $this->inner->acquire($key, $owner, $ttlSeconds);
            }

            public function release(string $key, string $owner): void
            {
                $this->inner->release($key, $owner);
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                return $this->inner->increment($key, $by, $ttlSeconds);
            }
        };
    }

    public function testUnreadableStateEntriesAreSurfacedAsACount(): void
    {
        $h = self::ready();
        $h->state = self::withUnreadable($h->state);

        $d = self::data($h->watcher(self::source())->run());

        self::assertSame(3, $d->stats?->stateUnreadable);
        self::assertStringContainsString('"state_unreadable":3', (new JsonSummary())->render($d));
    }

    public function testADryRunStillReportsTheUnreadableCountOfTheRealStore(): void
    {
        $h = self::ready();
        $h->state = self::withUnreadable($h->state);

        $d = self::data($h->watcher(self::source(), RunOptions::dryRun(), static function (string $s): void {
        })->run());

        self::assertSame(3, $d->stats?->stateUnreadable);
    }

    public function testNoUnreadableEntriesLeavesTheJsonLineUnchanged(): void
    {
        $d = self::data(self::ready()->watcher(self::source())->run());

        self::assertSame(0, $d->stats?->stateUnreadable);
        self::assertStringNotContainsString('state_unreadable', (new JsonSummary())->render($d));
    }
}
