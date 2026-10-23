<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Source\SourceUnavailable;
use Watcher\State\Keys;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Watcher;

final class DigestTest extends TestCase
{
    /** @return list<\Watcher\Source\LogEvent> */
    private static function alpha(int $n = 5): array
    {
        return Harness::events(Level::Error, 'Alpha service exploded badly', $n, 'AlphaException');
    }

    /** @return list<\Watcher\Source\LogEvent> */
    private static function beta(int $n): array
    {
        return Harness::events(Level::Error, 'Beta queue stalled hard', $n, 'BetaException');
    }

    /**
     * @param list<\Watcher\Source\LogEvent> $events
     * @param array<string, int>|null $totals
     */
    private static function source(array $events, ?array $totals = null): FakeLogSource
    {
        return new FakeLogSource($totals ?? Harness::totalsOf($events), $events);
    }

    public function testScenario1FirstRunBaselinesWithOneNoticeAndNoAnalysis(): void
    {
        $h = new Harness();
        // Totals jumped, but there is no previous snapshot: no second pass (FR-035).
        $source = self::source(self::alpha(5), ['error' => 5, 'info' => 6000]);

        $result = $h->watcher($source)->run();

        self::assertSame(RunOutcome::Baselined, $result->outcome);
        self::assertCount(1, $h->notifier->sent);
        self::assertStringContainsString('baseline recorded: 1 groups, 5 events', $h->sentTexts()[0]);
        self::assertSame([], $h->analyzer->requests);
        self::assertCount(1, $source->callsTo('lines'));
    }

    public function testScenario0LevelAnomalyRunsASecondCappedPassForThatLevelOnly(): void
    {
        $h = new Harness();
        $h->watcher(self::source(self::alpha(), ['error' => 5, 'info' => 1000]))->run();
        $h->nextWindow();

        $info = Harness::events(Level::Info, 'Cache warmed up again', 4);
        $source = self::source([...self::alpha(), ...$info], ['error' => 5, 'info' => 6000]);
        $result = $h->watcher($source)->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $lines = $source->callsTo('lines');
        self::assertCount(2, $lines);
        $levels = array_map(static fn (array $a): Level => $a[2]->minLevel, $lines);
        self::assertContains(Level::Info, $levels);
        $infoPass = $lines[array_search(Level::Info, $levels, true)];
        self::assertSame(100, $infoPass[2]->eventCap); // anomaly_cap from the test config
        self::assertStringContainsString('info ×6 vs previous window', $h->sentTexts()[1]);
        $templates = array_map(static fn ($g) => $g->errorType ?? strtolower($g->level->name), $h->analyzer->requests[0]->groups);
        self::assertContains('info', $templates); // the anomaly group reached the analyzer
    }

    public function testScenario2NewErrorGroupIsReportedWithFingerprintAndHypothesis(): void
    {
        $h = new Harness();
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();

        $result = $h->watcher(self::source([...self::alpha(), ...self::beta(2)]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertCount(2, $h->notifier->sent); // baseline + digest
        $beta = $this->fingerprintOf($h, 'BetaException');
        self::assertStringContainsString(substr($beta, 0, 12), $h->sentTexts()[1]);
        self::assertStringContainsString('Hypothesis:', $h->sentTexts()[1]);
        self::assertCount(1, $h->analyzer->requests);
    }

    public function testScenario3SameInputNextWindowIsQuietWithNoAnalysisAndNoMessage(): void
    {
        $h = new Harness();
        $events = [...self::alpha(), ...self::beta(2)];
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();
        $h->watcher(self::source($events))->run();
        $h->nextWindow();

        $result = $h->watcher(self::source($events))->run();

        self::assertSame(RunOutcome::Quiet, $result->outcome);
        self::assertCount(1, $h->analyzer->requests);
        self::assertCount(2, $h->notifier->sent);
    }

    public function testScenario4GroupGrowingFourfoldIsReportedAgainWithTrend(): void
    {
        $h = new Harness();
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();
        $h->watcher(self::source([...self::alpha(), ...self::beta(2)]))->run();
        $h->nextWindow();

        $result = $h->watcher(self::source([...self::alpha(), ...self::beta(8)]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertCount(3, $h->notifier->sent);
        self::assertStringContainsString('↑ from 2', $h->sentTexts()[2]);
        self::assertCount(1, $h->analyzer->requests[1]->groups); // only the grown group; unchanged Alpha is deduped
    }

    public function testRepeatingTheSameWindowIsSkippedDone(): void
    {
        $h = new Harness();
        $h->watcher(self::source(self::alpha()))->run();

        $source = self::source(self::alpha());
        $result = $h->watcher($source)->run();

        self::assertSame(RunOutcome::SkippedDone, $result->outcome);
        self::assertCount(1, $h->notifier->sent);
        self::assertSame([], $source->calls);
    }

    public function testHeldLockIsSkippedLockedWithoutExternalCalls(): void
    {
        $h = new Harness();
        $h->state->acquire($h->lockKey(), 'another-runner', 900);
        $source = self::source(self::alpha());

        $result = $h->watcher($source)->run();

        self::assertSame(RunOutcome::SkippedLocked, $result->outcome);
        self::assertSame([], $source->calls);
        self::assertSame([], $h->notifier->sent);
        self::assertSame([], $h->analyzer->requests);
    }

    public function testLockIsReleasedEvenWhenTheSourceFails(): void
    {
        $h = new Harness();
        $source = self::source([]);
        $source->failWith(new SourceUnavailable('loki 401'));

        $result = $h->watcher($source)->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertSame(1, $result->exitCode());
        self::assertStringContainsString('loki 401', (string) $result->failureReason);
        self::assertTrue($h->state->acquire($h->lockKey(), 'next', 60), 'lock must be free after a failed run');
        self::assertNull($h->state->get(Keys::run('svc', Watcher::windowKey($h->windowEnd()))), 'a failed run leaves no RunRecord');
    }

    public function testTruncationInTheAnomalyPassAloneIsStillReported(): void
    {
        $h = new Harness(['source' => ['anomaly_cap' => 100]]);
        $h->watcher(self::source(self::alpha(), ['error' => 5, 'info' => 1000]))->run();
        $h->nextWindow();

        $info = Harness::events(Level::Info, 'Cache warmed up again', 150);
        $result = $h->watcher(self::source([...self::alpha(), ...$info], ['error' => 5, 'info' => 6000]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertStringContainsString('truncated: 100 events read', $h->sentTexts()[1], 'the anomaly pass hit its cap; the main pass did not');
    }

    public function testEventsReadCountsMutedEventsToo(): void
    {
        $delta = Harness::events(Level::Error, 'Delta cache missed again', 5, 'DeltaException');
        $h = new Harness(['source' => ['event_cap' => 11], 'mute' => [['error_type' => '/BetaException/']]]);
        $h->watcher(self::source($delta))->run();
        $h->nextWindow();

        $h->watcher(self::source([...$delta, ...self::beta(20)]))->run();

        self::assertStringContainsString('truncated: 11 events read', $h->sentTexts()[1]);
    }

    public function testClusterCapBoundsGroupsAndStillAnalysesWatchedEvents(): void
    {
        $h = new Harness(['source' => ['group_cap' => 20], 'selection' => ['top_n' => 100], 'watch_rules' => ['vip' => ['field' => 'tenant', 'equals' => 'tenant-a']]]);
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();

        $events = self::alpha();
        $w = static fn (string $a): string => strtr($a, '0123456789', 'abcdefghij');
        for ($i = 0; $i < 100; ++$i) {
            $events[] = new \Watcher\Source\LogEvent(new \DateTimeImmutable('2026-10-07T12:00:00Z'), Level::Error, 'noise' . $w((string) $i) . ' other' . $w((string) $i) . ' broke', null, [], [], []);
        }
        $events[] = new \Watcher\Source\LogEvent(new \DateTimeImmutable('2026-10-07T12:00:00Z'), Level::Error, 'vipthing late arrival happened', null, [], ['tenant' => 'tenant-a'], []);
        $result = $h->watcher(self::source($events))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertStringContainsString('cluster cap hit: 81 events from distinct messages beyond the first 20 were grouped as other', $h->sentTexts()[1]);
        $seen = array_merge(...array_map(static fn ($r) => $r->groups, $h->analyzer->triageRequests));
        self::assertLessThanOrEqual(21 + 1, count($seen));
        self::assertContains(['vip'], array_map(static fn ($g) => $g->watchMatches, $seen));
    }

    public function testRegexEvaluationFailuresAreCountedInTheDigest(): void
    {
        $jit = ini_set('pcre.jit', '0');
        $limit = ini_set('pcre.backtrack_limit', '100');
        try {
            $h = new Harness(['mute' => [['field' => 'note', 'matches' => '/^(a+)+$/']]]);
            $h->watcher(self::source(self::alpha()))->run();
            $h->nextWindow();
            $events = array_map(static fn (\Watcher\Source\LogEvent $e) => new \Watcher\Source\LogEvent($e->time, $e->level, $e->message, $e->errorType, [], ['note' => str_repeat('a', 40) . 'b'], []), self::alpha());

            $h->watcher(self::source($events))->run();
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        self::assertMatchesRegularExpression('/\d+ pattern evaluation failures: rules may have been skipped/', implode("\n", $h->sentTexts()));
    }

    public function testAFieldMuteNeverHidesTheOverflowGroup(): void
    {
        $h = new Harness(['source' => ['group_cap' => 3], 'selection' => ['top_n' => 100], 'mute' => [['field' => 'tenant', 'equals' => 'noisy']]]);
        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();

        $events = self::alpha();
        $w = static fn (string $a): string => strtr($a, '0123456789', 'abcdefghij');
        for ($i = 0; $i < 10; ++$i) {
            $events[] = new \Watcher\Source\LogEvent(new \DateTimeImmutable('2026-10-07T12:00:00Z'), Level::Error, 'noise' . $w((string) $i) . ' other' . $w((string) $i) . ' broke', null, [], ['tenant' => 'noisy'], []);
        }
        $h->watcher(self::source($events))->run();

        $seen = array_merge(...array_map(static fn ($r) => $r->groups, $h->analyzer->triageRequests));
        self::assertContains('<other clusters>', array_map(static fn ($g) => $g->template, $seen));
        self::assertStringContainsString('cluster cap hit: 8 events', $h->sentTexts()[1]);
    }

    public function testEveryStateWriteCarriesATtl(): void
    {
        $h = new Harness();
        $spy = new TtlSpyStore(new InMemoryStateStore($h->clock));
        $h->state = $spy;

        $h->watcher(self::source(self::alpha()))->run();
        $h->nextWindow();
        $h->watcher(self::source([...self::alpha(), ...self::beta(2)]))->run();

        self::assertNotSame([], $spy->puts);
        foreach ($spy->puts as $key => $ttl) {
            self::assertSame(2592000, $ttl, $key); // default retention
        }
        foreach ($spy->locks as $ttl) {
            self::assertSame(900, $ttl); // lock_ttl
        }
        self::assertNotSame([], $spy->locks);
    }

    private function fingerprintOf(Harness $h, string $errorType): string
    {
        foreach ($h->analyzer->requests[0]->groups as $g) {
            if ($g->errorType === $errorType) {
                return $g->fingerprint;
            }
        }
        self::fail("no analysed group with errorType $errorType");
    }
}

/** Records the TTL of every write passed through to a real store. */
final class TtlSpyStore implements StateStore
{
    /** @var array<string, int> */
    public array $puts = [];
    /** @var array<string, int> */
    public array $locks = [];

    public function __construct(private readonly StateStore $inner)
    {
    }

    public function get(string $key): ?array
    {
        return $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->puts[$key] = $ttlSeconds;
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->locks[$key] = $ttlSeconds;

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
}
