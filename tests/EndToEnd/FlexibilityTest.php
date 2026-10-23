<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\Fingerprinter;
use Watcher\Reduce\Normalizer;
use Watcher\RunOutcome;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class FlexibilityTest extends TestCase
{
    private const SECRET = 'tenant-a-tenant-secret-value';

    /** @param list<LogEvent> $events */
    private static function source(array $events): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /** @return list<LogEvent> */
    private static function quota(int $n = 4): array
    {
        return Harness::events(Level::Warning, 'QuotaTracking drifted again', $n);
    }

    /** @return list<LogEvent> */
    private static function alpha(): array
    {
        return Harness::events(Level::Error, 'Alpha service exploded badly', 5, 'AlphaException');
    }

    /** @return list<LogEvent> */
    private static function beta(): array
    {
        return Harness::events(Level::Error, 'Beta queue stalled hard', 5, 'BetaException');
    }

    /**
     * Baselines the given events, then moves to the next window.
     *
     * @param list<LogEvent> $events
     */
    private static function baseline(Harness $h, array $events): void
    {
        $h->watcher(self::source($events))->run();
        $h->nextWindow();
    }

    private static function fingerprintOf(LogEvent $e): string
    {
        return (new Fingerprinter(new Normalizer([], [])))->of($e);
    }

    /** @return string everything any model would have seen */
    private static function payloads(Harness $h): string
    {
        return json_encode(array_map(static fn ($r): array => $r->groups, $h->analyzer->allRequests()), JSON_THROW_ON_ERROR);
    }

    private static function everything(Harness $h): string
    {
        assert($h->state instanceof InMemoryStateStore);

        return self::payloads($h) . implode("\n", $h->sentTexts()) . json_encode($h->state->all(), JSON_THROW_ON_ERROR);
    }

    public function testScenario6TemplateWatchRuleMakesAWarningGroupACandidateByNameOnly(): void
    {
        $h = new Harness(['watch_rules' => ['quota' => ['template' => '/QuotaTracking/']]]);
        self::baseline($h, self::quota());

        $result = $h->watcher(self::source(self::quota()))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $groups = $h->analyzer->triageRequests[0]->groups;
        self::assertCount(1, $groups);
        self::assertSame(['quota'], $groups[0]->watchMatches);
        self::assertStringNotContainsString('/QuotaTracking/', self::payloads($h));
    }

    public function testScenario6WithoutTheRuleTheSameWarningGroupIsNotACandidate(): void
    {
        $h = new Harness(['selection' => ['top_n' => 1, 'top_m' => 1]]);
        $events = [...self::quota(), ...self::alpha()];
        self::baseline($h, $events);

        $h->watcher(self::source($events))->run();

        self::assertStringNotContainsString('QuotaTracking', self::payloads($h));
        self::assertStringContainsString('AlphaException', self::payloads($h));
    }

    public function testScenario6FieldRuleMatchesViaEventFieldsAndTheValueNeverLeaves(): void
    {
        $h = new Harness(['watch_rules' => ['tenant-a' => ['field' => 'tenant', 'equals' => self::SECRET]]]);
        $plain = self::quota();
        $withField = array_map(
            static fn (LogEvent $e): LogEvent => new LogEvent($e->time, $e->level, $e->message, $e->errorType, [], ['tenant' => self::SECRET], []),
            self::quota(),
        );
        self::baseline($h, $plain);

        $result = $h->watcher(self::source($withField))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertSame(['tenant-a'], $h->analyzer->triageRequests[0]->groups[0]->watchMatches);
        self::assertStringNotContainsString(self::SECRET, self::everything($h));
    }

    public function testScenario7FingerprintAndMatcherMutesExcludeGroupsAndAreCounted(): void
    {
        $alpha = self::alpha();
        $h = new Harness(['mute' => [
            ['fingerprint' => self::fingerprintOf($alpha[0])],
            ['error_type' => '/BetaException/'],
        ]]);
        $gamma = Harness::events(Level::Error, 'Gamma export crashed hard', 5, 'GammaException');
        self::baseline($h, $gamma);

        $result = $h->watcher(self::source([...$alpha, ...self::beta(), ...$gamma, ...Harness::events(Level::Error, 'Delta cache missed again', 5, 'DeltaException')]))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $seen = self::payloads($h);
        self::assertStringNotContainsString('AlphaException', $seen);
        self::assertStringNotContainsString('BetaException', $seen);
        self::assertStringContainsString('DeltaException', $seen);
        $texts = $h->sentTexts();
        self::assertStringContainsString('2 muted', $texts[count($texts) - 1]);
    }

    public function testScenario7FieldMatcherMuteExcludesTheGroupWithoutLeakingTheValue(): void
    {
        $h = new Harness(['mute' => [['field' => 'tenant', 'equals' => self::SECRET]]]);
        self::baseline($h, self::beta());
        $noisy = array_map(
            static fn (LogEvent $e): LogEvent => new LogEvent($e->time, $e->level, $e->message, $e->errorType, [], ['tenant' => self::SECRET], []),
            self::alpha(),
        );

        $h->watcher(self::source([...$noisy, ...Harness::events(Level::Error, 'Delta cache missed again', 5, 'DeltaException')]))->run();

        self::assertStringNotContainsString('AlphaException', self::payloads($h));
        self::assertStringContainsString('1 muted', implode("\n", $h->sentTexts()));
        self::assertStringNotContainsString(self::SECRET, self::everything($h));
    }

    public function testScenario7GroupIsAnalysedAgainOnceTheMuteHasExpired(): void
    {
        // 2026-10-08 baseline, 10-09 muted (until is inclusive), 10-10 expired.
        $h = new Harness(['mute' => [['error_type' => '/AlphaException/', 'until' => '2026-10-09']]]);
        self::baseline($h, self::beta());

        $h->watcher(self::source([...self::alpha(), ...self::beta(), ...Harness::events(Level::Error, 'Gamma export crashed hard', 5, 'GammaException')]))->run();
        self::assertStringNotContainsString('AlphaException', self::payloads($h));
        $h->analyzer->triageRequests = [];
        $h->analyzer->requests = [];
        $h->nextWindow();

        $h->watcher(self::source([...self::alpha(), ...self::beta()]))->run();

        self::assertStringContainsString('AlphaException', self::payloads($h));
    }

    public function testScenario8NotDueMeansNoExternalCallAtAll(): void
    {
        $h = new Harness(['schedule' => ['run_at' => '06:00']]);
        $h->clock->set(new DateTimeImmutable('2026-10-08T05:00:00', new DateTimeZone('UTC')));
        $source = self::source(self::alpha());

        $result = $h->watcher($source)->run();

        self::assertSame(RunOutcome::SkippedNotDue, $result->outcome);
        self::assertSame([], $source->calls);
        self::assertSame([], $h->analyzer->allRequests());
        self::assertSame([], $h->notifier->sent);
        assert($h->state instanceof InMemoryStateStore);
        self::assertSame([], $h->state->all());
    }

    public function testScenario8RunsAtRunAtAndStillAt0659ButNotAt0700(): void
    {
        foreach (['06:00' => true, '06:59' => true, '07:00' => false] as $time => $due) {
            $h = new Harness(['schedule' => ['run_at' => '06:00']]);
            $h->clock->set(new DateTimeImmutable("2026-10-08T$time:00", new DateTimeZone('UTC')));

            $outcome = $h->watcher(self::source(self::alpha()))->run()->outcome;

            self::assertSame($due ? RunOutcome::Baselined : RunOutcome::SkippedNotDue, $outcome, $time);
        }
    }

    public function testScenario8SecondTriggerInTheSameHourIsSkippedDone(): void
    {
        $h = new Harness(['schedule' => ['run_at' => '06:00']]);
        $h->watcher(self::source(self::alpha()))->run();
        $h->clock->advance('+30 minutes');
        $source = self::source(self::alpha());

        self::assertSame(RunOutcome::SkippedDone, $h->watcher($source)->run()->outcome);
        self::assertSame([], $source->callsTo('lines'));
        self::assertCount(1, $h->notifier->sent);
    }

    public function testScenario9OnlyOneOfTwoRunnersSharingAStoreReports(): void
    {
        $h = new Harness();
        self::baseline($h, self::beta());
        $other = 'runner-two';
        self::assertTrue($h->state->acquire($h->lockKey(), $other, 900)); // runner two is mid-run

        $blocked = $h->watcher(self::source(self::alpha()))->run();
        self::assertSame(RunOutcome::SkippedLocked, $blocked->outcome);
        self::assertCount(1, $h->notifier->sent, 'only the baseline notice so far');

        $h->state->release($h->lockKey(), $other);
        $first = $h->watcher(self::source(self::alpha()))->run();
        $second = $h->watcher(self::source(self::alpha()))->run();

        self::assertSame(RunOutcome::Reported, $first->outcome);
        self::assertSame(RunOutcome::SkippedDone, $second->outcome);
        self::assertCount(2, $h->notifier->sent, 'baseline notice plus exactly one digest');
    }
}
