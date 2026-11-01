<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\VerdictCache;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Watcher;

/** Security review 5: a model that is wrong, hostile or refusing never makes an error group vanish unexplained, and never re-bills a window endlessly. */
final class ModelDistrustTest extends TestCase
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

    /** Below Error: a first run with Error groups now reports (FR-035), and these tests start from a baseline. */
    private static function baselined(Harness $h): void
    {
        $h->watcher(self::source(Harness::events(Level::Warning, 'Alpha failed in a unique way', 5, 'Alpha')))->run();
        $h->nextWindow();
    }

    private static function runKey(Harness $h): string
    {
        return Keys::run('svc', Watcher::windowKey($h->windowEnd()));
    }

    /** A model that says "noise" to everything and then "nothing to report". */
    private static function hostile(): HostileModel
    {
        return new HostileModel();
    }

    public function testAnErrorGroupSilencedByTheModelEndsAsAReportedRunWithVisibleReasonsNeverAnUnexplainedQuiet(): void
    {
        $h = new Harness();
        self::baselined($h);
        $model = self::hostile();
        $w = new Watcher(\Watcher\Config\WatchConfig::fromArray($h->config()), self::source(self::alpha(9)), $h->state, $model, $h->notifier, $h->clock);

        $result = $w->run();

        self::assertSame(RunOutcome::Reported, $result->outcome, 'not Quiet');
        self::assertSame(1, $model->analyzeCalls, 'triage "noise" was overruled for an error group');
        $text = implode("\n", $h->sentTexts());
        self::assertStringContainsString('triage marked 1 error-or-higher or watch-rule group(s) as noise', $text);
        self::assertStringContainsString('1 error-or-higher or watch-rule group(s) were analysed but the model reported nothing', $text);
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        self::assertSame([], array_filter(array_keys($h->state->all()), static fn (int|string $k): bool => str_starts_with((string) $k, Keys::VERDICT . ':')), 'nothing was remembered about it');
    }

    public function testTheNextWindowAnalysesTheSameGroupAgainInsteadOfReusingASilentVerdict(): void
    {
        $h = new Harness();
        self::baselined($h);
        $model = self::hostile();
        $make = fn (): Watcher => new Watcher(\Watcher\Config\WatchConfig::fromArray($h->config()), self::source(self::alpha(9)), $h->state, $model, $h->notifier, $h->clock);
        $make()->run();
        $h->nextWindow();

        $make()->run();

        self::assertSame(2, $model->analyzeCalls);
    }

    public function testGroupsReusedFromEarlierVerdictsAreCountedInTheDigest(): void
    {
        $h = new Harness();
        self::baselined($h);
        $h->watcher(self::source(self::alpha(10)))->run();
        $fp = $h->analyzer->requests[0]->groups[0]->fingerprint;
        // an older, much larger verdict: today's alpha (30) is still far below growth_factor x 1000
        (new VerdictCache($h->state, 'svc', 2592000))->put($fp, 'interesting', 1000, new Finding([$fp], FindingKind::Bug, Severity::Error, 'Alpha', 'e', 'c', 'a', 0.9));
        $h->nextWindow();
        $events = [...self::alpha(30), ...Harness::events(Level::Error, 'Beta failed in a unique way', 8, 'Beta')];

        $h->watcher(self::source($events))->run();

        $text = $h->sentTexts()[count($h->sentTexts()) - 1];
        self::assertStringContainsString('1 group(s) reused from earlier verdicts', $text);
    }

    public function testARefusalIsRecordedAsFailedWithACodeAndNotRetriedByTheNextTrigger(): void
    {
        $h = new Harness();
        self::baselined($h);
        $h->analyzer->failure = new AnalyzerFailed('triage: the model refused the request (not retried)', true);

        $first = $h->watcher(self::source(self::alpha(9)))->run();

        self::assertSame(RunOutcome::Failed, $first->outcome);
        self::assertSame('analysis_refused', $first->summaryData?->reason);
        self::assertStringContainsString('triage: the model refused the request', $h->sentTexts()[count($h->sentTexts()) - 1]);
        $record = $h->state->get(self::runKey($h));
        self::assertSame('failed', $record['outcome'] ?? null);
        self::assertSame('analysis_refused', $record['code'] ?? null);

        $calls = count($h->analyzer->triageRequests);
        $again = $h->watcher(self::source(self::alpha(9)))->run();

        self::assertSame(RunOutcome::SkippedDone, $again->outcome);
        self::assertCount($calls, $h->analyzer->triageRequests, 'the window is not billed again');
    }

    public function testASecondFailureOfTheSameWindowStopsTheRetries(): void
    {
        $h = new Harness();
        self::baselined($h);
        $h->analyzer->failure = new AnalyzerFailed('provider unavailable');

        $h->watcher(self::source(self::alpha(9)))->run();
        self::assertNull($h->state->get(self::runKey($h)), 'the first failure still lets the next trigger retry');

        $second = $h->watcher(self::source(self::alpha(9)))->run();

        self::assertSame(RunOutcome::Failed, $second->outcome);
        self::assertStringContainsString('no further attempts for this window', $h->sentTexts()[count($h->sentTexts()) - 1]);
        $record = (fn (): ?array => $h->state->get(self::runKey($h)))();
        self::assertSame('failed', $record['outcome'] ?? null);
        $calls = count($h->analyzer->triageRequests);
        self::assertSame(RunOutcome::SkippedDone, $h->watcher(self::source(self::alpha(9)))->run()->outcome);
        self::assertCount($calls, $h->analyzer->triageRequests);

        $h->nextWindow();
        $h->analyzer->failure = null;
        self::assertSame(RunOutcome::Reported, $h->watcher(self::source(self::alpha(12)))->run()->outcome, 'the next window starts clean');
    }
}

/** Says "noise" to everything, then "nothing to report". */
final class HostileModel implements Analyzer
{
    public int $analyzeCalls = 0;

    public function triage(TriageRequest $req): TriageResult
    {
        return new TriageResult(array_fill_keys(array_map(static fn ($g) => $g->fingerprint, $req->groups), 'noise'));
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        ++$this->analyzeCalls;

        return new AnalysisResult([]);
    }
}
