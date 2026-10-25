<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Budget;
use Watcher\Analyze\BudgetReservation;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\ModelRouter;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\RouterResult;
use Watcher\Analyze\RouterSettings;
use Watcher\Analyze\Severity;
use Watcher\Analyze\Spend;
use Watcher\Analyze\TriageCheckpoint;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\Usage;
use Watcher\Analyze\VerdictCache;
use Watcher\Context\DependencyContext;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\Tests\Fakes\FakeAnalyzer;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class ModelRouterTest extends TestCase
{
    private FakeAnalyzer $fake;

    private VerdictCache $cache;

    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->fake = new FakeAnalyzer();
        $this->store = new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-23T06:00:00+00:00')));
        $this->cache = new VerdictCache($this->store, 'svc', 1000);
    }

    private function router(int $topM = 10, int $cap = 2, bool $cache = true, int $escalateMaxInput = PHP_INT_MAX, ?Analyzer $analyzer = null, ?TriageCheckpoint $checkpoint = null): ModelRouter
    {
        return new ModelRouter($analyzer ?? $this->fake, $this->cache, new RouterSettings('m-triage', 'm-analyze', 'm-escalate', 111, 222, 333, $topM, $cap, 0.6, 2.0, 'ctx', $escalateMaxInput, true, false), $checkpoint);
    }

    /** $100 per million tokens for triage, $200 for analysis, $100,000 for escalation: here triage costs about $0.23 and analysis about $0.61 at worst (two attempts each). */
    private function reservation(float $spent, float $budget = 0.50, ?int $tokenLimit = null): BudgetReservation
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-23T06:00:00+00:00'));
        $spend = new Spend($this->store, $clock, new DateTimeZone('UTC'), 'svc');
        $spend->add(new Usage('m-triage', 1, 1), $spent);
        $prices = ['m-triage' => [100, 100, 0, 0], 'm-analyze' => [200, 200, 0, 0], 'm-escalate' => [100000, 100000, 0, 0]];

        return new BudgetReservation($spend, new CostCalculator($prices), $budget, $tokenLimit);
    }

    private static function group(string $fp, int $n = 10, Level $lvl = Level::Error, GroupStatusLabel $st = GroupStatusLabel::New): RedactedGroup
    {
        return new RedactedGroup($fp, 'tpl ' . $fp, $lvl, null, [], $n, null, $st, [], new DateTimeImmutable(), new DateTimeImmutable());
    }

    /** @param array<array-key,string> $v all-digit fingerprints become int keys */
    private static function triage(array $v, string $model = 'tm'): TriageResult
    {
        return new TriageResult($v, new Usage($model, 10, 1));
    }

    private static function finding(string $fp, Severity $sev = Severity::Error, float $conf = 0.9): Finding
    {
        return new Finding([$fp], FindingKind::Bug, $sev, 'T ' . $fp, 'e', 'c', 'a', $conf);
    }

    /** @param list<Finding> $f */
    private static function analysis(array $f, string $model = 'am'): AnalysisResult
    {
        return new AnalysisResult($f, new Usage($model, 100, 10));
    }

    public function testEmptyInputMakesNoCalls(): void
    {
        $r = $this->router()->route([], false);

        self::assertSame(0, $this->fake->triageCalls + $this->fake->analyzeCalls);
        self::assertSame([], $r->findings);
        self::assertSame([], $r->usageByStep);
        self::assertSame([0, 0, 0, 0], [$r->triaged, $r->reused, $r->escalations, $r->droppedByTopM]);
    }

    public function testAdviceRaisesTheAnalysisOutputCapToTheWorstCaseOfItsGroups(): void
    {
        $this->fake->triage = self::triage(['c' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding('c')]);
        $router = new ModelRouter($this->fake, $this->cache, new RouterSettings('m-triage', 'm-analyze', 'm-escalate', 111, 222, 333, 10, 2, 0.6, 2.0, 'ctx'), null);

        $router->route([self::group('c')], false);

        $an = $this->fake->requests[1];
        self::assertInstanceOf(AnalysisRequest::class, $an);
        self::assertSame(750, $an->maxOutputTokens, '450 per group + 300 beats the configured 222');
    }

    public function testAMissingTriageVerdictIsAnalysedAndCounted(): void
    {
        $this->fake->triage = self::triage(['a' => 'noise']); // 'b' (a warning) was sent but got no verdict
        $this->fake->analysis = self::analysis([self::finding('b')]);

        $r = $this->router()->route([self::group('a', 10, Level::Warning), self::group('b', 10, Level::Warning)], false);

        $an = $this->fake->requests[1];
        self::assertInstanceOf(AnalysisRequest::class, $an);
        self::assertSame(['b'], array_map(static fn ($g) => $g->fingerprint, $an->groups));
        self::assertSame(1, $r->triageCoerced);
    }

    public function testRoutingTable(): void
    {
        $this->fake->triage = self::triage(['a' => 'noise', 'b' => 'known', 'c' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding('c')]);

        $r = $this->router()->route([self::group('a', 10, Level::Warning), self::group('b', 10, Level::Warning), self::group('c')], false);

        self::assertSame(1, $this->fake->triageCalls);
        self::assertSame(1, $this->fake->analyzeCalls);
        $tri = $this->fake->requests[0];
        self::assertInstanceOf(TriageRequest::class, $tri);
        self::assertSame(['a', 'b', 'c'], array_map(static fn ($g) => $g->fingerprint, $tri->groups));
        self::assertSame(['m-triage', 111, 'ctx'], [$tri->model, $tri->maxOutputTokens, $tri->serviceContext]);
        $an = $this->fake->requests[1];
        self::assertInstanceOf(AnalysisRequest::class, $an);
        self::assertSame(['c'], array_map(static fn ($g) => $g->fingerprint, $an->groups));
        self::assertSame(['m-analyze', 222], [$an->model, $an->maxOutputTokens]);
        self::assertCount(1, $r->findings);
        self::assertSame(3, $r->triaged);
        self::assertSame(['triage', 'analyze'], array_keys($r->usageByStep));
        // verdicts were stored
        self::assertSame('noise', $this->cache->get('a')['triage'] ?? null);
        self::assertSame('interesting', $this->cache->get('c')['triage'] ?? null);
    }

    public function testAllDigitFingerprintIsStoredUnderItsStringKey(): void
    {
        // 'Lockwl timeout for order' fingerprints to an all-digit id; PHP array keys would make it an int.
        $fp = '694072486490';
        $this->fake->triage = self::triage([$fp => 'interesting']);
        $this->fake->analysis = self::analysis([]);

        $r = $this->router()->route([self::group($fp, 10, Level::Warning)], false); // an error group with no finding is no longer remembered (F1)

        self::assertSame([], $r->findings);
        self::assertSame('interesting', $this->cache->get($fp)['triage'] ?? null);
    }

    public function testTopMKeepsHighestPriorityAndCountsTheRest(): void
    {
        $this->fake->triage = self::triage(['low' => 'interesting', 'mid' => 'interesting', 'new' => 'interesting', 'big' => 'interesting']);
        $this->fake->analysis = self::analysis([]);
        $groups = [
            self::group('low', 5, Level::Warning, GroupStatusLabel::Unchanged),
            self::group('mid', 5, Level::Error, GroupStatusLabel::Grown),
            self::group('new', 1, Level::Error, GroupStatusLabel::New),
            self::group('big', 99, Level::Error, GroupStatusLabel::Grown),
        ];

        $r = $this->router(topM: 3)->route($groups, false);

        $an = $this->fake->requests[1];
        self::assertInstanceOf(AnalysisRequest::class, $an);
        // F5: at the same level growth ranks before novelty (a minted fingerprint is always new), so 'new' is last.
        self::assertSame(['big', 'mid', 'new'], array_map(static fn ($g) => $g->fingerprint, $an->groups));
        self::assertSame(1, $r->droppedByTopM);
    }

    public function testTriageOnlyMakesNoAnalyzeCall(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);

        $r = $this->router()->route([self::group('a')], true);

        self::assertSame(0, $this->fake->analyzeCalls);
        self::assertSame([], $r->findings);
        self::assertSame(['triage'], array_keys($r->usageByStep));
    }

    public function testNoInterestingGroupsMeansNoAnalyzeCall(): void
    {
        $this->fake->triage = self::triage(['a' => 'noise']);

        $this->router()->route([self::group('a', 10, Level::Warning)], false); // an error group can no longer be triaged away (security review 5, F2)

        self::assertSame(0, $this->fake->analyzeCalls);
    }

    public function testReusedGroupsAreNotSentAnywhere(): void
    {
        $this->cache->put('old', 'known', 10, null);
        $this->fake->triage = self::triage(['new' => 'noise']);

        $r = $this->router()->route([self::group('old', 19, Level::Warning), self::group('new', 10, Level::Warning)], false);

        self::assertSame(1, $r->reused);
        self::assertSame(1, $r->triaged);
        self::assertSame(['new'], array_map(static fn ($g) => $g->fingerprint, $this->fake->requests[0]->groups));
    }

    public function testAllReusedMeansZeroAnalyzerCalls(): void
    {
        $this->cache->put('old', 'known', 10, null);

        $r = $this->router()->route([self::group('old', 12, Level::Warning)], false);

        self::assertSame(0, $this->fake->triageCalls + $this->fake->analyzeCalls);
        self::assertSame(1, $r->reused);
    }

    public function testGrownPastFactorIsRetriaged(): void
    {
        $this->cache->put('old', 'known', 10, null);
        $this->fake->triage = self::triage(['old' => 'noise']);

        $r = $this->router()->route([self::group('old', 20, Level::Warning)], false);

        self::assertSame(0, $r->reused);
        self::assertSame(1, $this->fake->triageCalls);
    }

    public function testEscalationOnlyForSevereLowConfidenceFindingsOnce(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'interesting', 'c' => 'interesting']);
        $this->fake->analysis = self::analysis([
            self::finding('a', Severity::Error, 0.3),   // escalates
            self::finding('b', Severity::Warn, 0.1),    // too mild
            self::finding('c', Severity::Critical, 0.9), // confident
        ]);
        $this->fake->analysisScript = [$this->fake->analysis, self::analysis([self::finding('a', Severity::Critical, 0.95)], 'em')];

        $r = $this->router()->route([self::group('a'), self::group('b'), self::group('c')], false);

        self::assertSame(2, $this->fake->analyzeCalls);
        $esc = $this->fake->requests[2];
        self::assertInstanceOf(AnalysisRequest::class, $esc);
        self::assertSame(['a'], array_map(static fn ($g) => $g->fingerprint, $esc->groups));
        self::assertSame(['m-escalate', 333], [$esc->model, $esc->maxOutputTokens]);
        self::assertSame(1, $r->escalations);
        $byFp = [];
        foreach ($r->findings as $f) {
            $byFp[$f->fingerprints[0]] = $f;
        }
        self::assertCount(3, $r->findings);
        self::assertTrue($byFp['a']->escalated);
        self::assertSame(Severity::Critical, $byFp['a']->severity);
        self::assertFalse($byFp['b']->escalated);
        self::assertFalse($byFp['c']->escalated);
        self::assertSame(['triage', 'analyze', 'escalate'], array_keys($r->usageByStep));
        self::assertSame('em', $r->usageByStep['escalate']->model);
    }

    public function testEscalationIsCappedPerRun(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'interesting', 'c' => 'interesting']);
        $first = self::analysis([self::finding('a', Severity::Error, 0.1), self::finding('b', Severity::Error, 0.1), self::finding('c', Severity::Error, 0.1)]);
        $this->fake->analysisScript = [$first, self::analysis([self::finding('a')]), self::analysis([self::finding('b')]), self::analysis([self::finding('c')])];

        $r = $this->router(cap: 2)->route([self::group('a'), self::group('b'), self::group('c')], false);

        self::assertSame(3, $this->fake->analyzeCalls, '1 analyze + 2 escalations');
        self::assertSame(2, $r->escalations);
        self::assertSame(3, count($r->findings));
        self::assertSame([true, true, false], array_map(static fn (Finding $f) => $f->escalated, $r->findings));
    }

    public function testEscalationWithEmptyResultKeepsTheOriginalFinding(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysisScript = [self::analysis([self::finding('a', Severity::Error, 0.1)]), self::analysis([])];

        $r = $this->router()->route([self::group('a')], false);

        self::assertCount(1, $r->findings);
        self::assertFalse($r->findings[0]->escalated);
        self::assertSame(1, $r->escalations);
    }

    public function testAnalyzerFailurePropagates(): void
    {
        $this->fake->failure = new AnalyzerFailed('boom');

        $this->expectException(AnalyzerFailed::class);
        $this->router()->route([self::group('a')], false);
    }

    public function testIsDeterministic(): void
    {
        $run = function (): string {
            $this->setUp();
            $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'interesting']);
            $this->fake->analysis = self::analysis([self::finding('a'), self::finding('b')]);
            $r = $this->router(topM: 1)->route([self::group('a', 3), self::group('b', 3)], false);

            return json_encode([$r->droppedByTopM, array_map(static fn ($g) => $g->fingerprint, $this->fake->requests[1]->groups)], JSON_THROW_ON_ERROR);
        };

        self::assertSame($run(), $run());
    }

    public function testInterestingWithoutAnalysisIsNotStoredAsAVerdict(): void
    {
        // Triage-only day: the group was judged interesting but never analysed.
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->router()->route([self::group('a')], true);

        self::assertNull($this->cache->get('a'), 'a verdict that was never acted on must not be reusable');

        // The next day, with budget, the same group is triaged and analysed again.
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding('a')]);
        $r = $this->router()->route([self::group('a', 11)], false);

        self::assertSame(0, $r->reused);
        self::assertSame(1, $this->fake->analyzeCalls);
        self::assertCount(1, $r->findings);
    }

    public function testInterestingDroppedByTopMIsNotStoredAsAVerdict(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding('a')]);

        $r = $this->router(1)->route([self::group('a', 50), self::group('b', 5)], false);

        self::assertSame(1, $r->droppedByTopM);
        self::assertNotNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'), 'the dropped group was never analysed and must be retried');
    }

    public function testAnalysedInterestingGroupWithoutAFindingIsStoredSoItIsNotPaidForAgain(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysis = self::analysis([]); // the analysis model found nothing worth reporting

        $this->router()->route([self::group('a', 10, Level::Warning)], false); // error and above: see ModelRouterFailClosedTest

        self::assertNotNull($this->cache->get('a'));
        self::assertTrue($this->cache->isReusable(self::group('a', 11, Level::Warning), 2.0));
    }

    public function testNoiseVerdictOfAnErrorGroupIsNeverStoredSoItIsTriagedAgain(): void
    {
        $this->fake->triage = self::triage(['a' => 'noise']);
        $this->fake->analysis = self::analysis([]); // F2: the verdict is overruled, so the group is analysed

        $this->router()->route([self::group('a', 10, Level::Error)], false);

        self::assertNull($this->cache->get('a'));
    }

    public function testTriageIsSkippedWhenItsWorstCaseDoesNotFitTheRemainingBudget(): void
    {
        $r = $this->router()->route([self::group('a'), self::group('b')], false, [], $this->reservation(0.49));

        self::assertSame(0, $this->fake->triageCalls + $this->fake->analyzeCalls, 'a run starting at $0.49 of $0.50 pays for nothing it cannot afford');
        self::assertSame(2, $r->notTriaged);
        self::assertSame([], $r->usageByStep);
    }

    public function testAnalysisIsSkippedWhenOnlyTriageFits(): void
    {
        $this->fake->triage = self::triage(['a' => 'noise', 'b' => 'interesting']);

        // analysis and escalation reserve both attempts of a step (F5): triage fits an empty $0.50 day, the analysis (about $0.61) does not
        $r = $this->router()->route([self::group('a', 10, Level::Warning), self::group('b')], false, [], $this->reservation(0.0));

        self::assertSame(1, $this->fake->triageCalls);
        self::assertSame(0, $this->fake->analyzeCalls);
        self::assertSame(1, $r->notAnalysed, 'the interesting group is counted, not lost');
        self::assertSame(0, $r->notTriaged);
        self::assertNull($this->cache->get('b'), 'never analysed, so never a reusable verdict');
    }

    public function testEverythingRunsWhenTheWorstCaseFits(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding('a')]);

        $r = $this->router()->route([self::group('a')], false, [], $this->reservation(0.0, 10.0));

        self::assertSame([1, 1], [$this->fake->triageCalls, $this->fake->analyzeCalls]);
        self::assertSame([0, 0], [$r->notTriaged, $r->notAnalysed]);
    }

    public function testTokenLimitIsReservedTheSameWay(): void
    {
        $this->fake->triage = self::triage(['a' => 'noise']);

        $this->router()->route([self::group('a', 10, Level::Warning)], false, [], $this->reservation(0.0, 10.0, 500));
        self::assertSame(0, $this->fake->triageCalls, 'the triage prompt alone is more than 500 tokens');

        $this->router()->route([self::group('a', 10, Level::Warning)], false, [], $this->reservation(0.0, 10.0, 100000));
        self::assertSame(1, $this->fake->triageCalls);
    }

    public function testTriageOnlyDayStillTriagesWithoutAReservation(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);

        $r = $this->router()->route([self::group('a')], true, [], $this->reservation(0.60));

        self::assertSame(1, $this->fake->triageCalls, 'a spent budget is the documented triage-only mode, unchanged');
        self::assertSame(1, $r->notAnalysed);
    }

    public function testEscalationsAreReservedOneByOne(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding('a', Severity::Error, 0.1), self::finding('b', Severity::Error, 0.1)]);

        $r = $this->router()->route([self::group('a'), self::group('b')], false, [], $this->reservation(0.0, 10.0)); // escalation: ~$100+

        self::assertSame(1, $this->fake->analyzeCalls, 'no escalation call');
        self::assertSame([0, 2], [$r->escalations, $r->escalationsSkippedBudget]);
        self::assertCount(2, $r->findings);
        self::assertFalse($r->findings[0]->escalated);
    }

    private static function wide(string $fp): RedactedGroup
    {
        $frames = array_map(static fn (int $i): string => 'App\\Deep\\Namespace\\Service' . $i . '::handleRequest() at src/Deep/Service' . $i . '.php:' . (100 + $i), range(1, 5));

        return new RedactedGroup($fp, 'tpl ' . $fp, Level::Error, null, $frames, 10, null, GroupStatusLabel::New, [], new DateTimeImmutable(), new DateTimeImmutable());
    }

    /** @return list<DependencyContext> */
    private static function dependency(): array
    {
        $top = array_map(static fn (int $i): RedactedGroup => new RedactedGroup('d' . $i, 'Connection to upstream host number ' . $i . ' timed out after <N> ms while calling the payment endpoint', Level::Error, null, [], 10 + $i, null, GroupStatusLabel::New, [], new DateTimeImmutable(), new DateTimeImmutable()), range(1, 5));

        return [new DependencyContext('dep', true, ['error' => 15], null, $top, [0], false)];
    }

    private function escalate(int $maxInput): RouterResult
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysisScript = [self::analysis([self::finding('a', Severity::Error, 0.1)]), self::analysis([self::finding('a', Severity::Critical, 0.95)])];

        return $this->router(escalateMaxInput: $maxInput)->route([self::wide('a')], false, self::dependency());
    }

    /** @param list<DependencyContext> $deps */
    private static function inputOf(RedactedGroup $g, array $deps): int
    {
        return Budget::fixedTokens('analyze', 'ctx', false) + Budget::estimateTokens(CompactPayload::encode([$g], $deps)); // fixed input includes the data wrapper of F3
    }

    public function testEscalationSendingMoreThanTheLimitTrimsDependencyContextFirst(): void
    {
        $bare = array_map(static fn (DependencyContext $d): DependencyContext => $d->withTopErrors([]), self::dependency());
        $r = $this->escalate(self::inputOf(self::wide('a'), $bare) + 5);

        $esc = $this->fake->requests[2];
        self::assertInstanceOf(AnalysisRequest::class, $esc);
        self::assertSame(1, $r->escalations);
        self::assertSame([], $esc->dependencyContext[0]->topErrors, 'dependency top errors went first');
        self::assertCount(5, $esc->groups[0]->frames, 'evidence untouched');
    }

    public function testThenEvidenceIsTrimmed(): void
    {
        $bare = array_map(static fn (DependencyContext $d): DependencyContext => $d->withTopErrors([]), self::dependency());
        $noFrames = new RedactedGroup('a', 'tpl a', Level::Error, null, [], 10, null, GroupStatusLabel::New, [], new DateTimeImmutable(), new DateTimeImmutable());
        $r = $this->escalate(self::inputOf($noFrames, $bare) + 5);

        $esc = $this->fake->requests[2];
        self::assertInstanceOf(AnalysisRequest::class, $esc);
        self::assertSame(1, $r->escalations);
        self::assertSame([], $esc->groups[0]->frames);
        self::assertSame('tpl a', $esc->groups[0]->template, 'the template is the finding itself and stays');
    }

    public function testEscalationThatCannotFitIsSkippedWithACountedReason(): void
    {
        $r = $this->escalate(Budget::fixedTokens('analyze', 'ctx', false));

        self::assertSame(1, $this->fake->analyzeCalls, 'no escalation call');
        self::assertSame([0, 1, 0], [$r->escalations, $r->escalationsSkippedSize, $r->escalationsSkippedBudget]);
        self::assertCount(1, $r->findings);
        self::assertFalse($r->findings[0]->escalated, 'the first-pass finding is reported as it is');
    }

    public function testAFittingEscalationIsSentWholeAndUntouched(): void
    {
        $r = $this->escalate(1_000_000);

        $esc = $this->fake->requests[2];
        self::assertInstanceOf(AnalysisRequest::class, $esc);
        self::assertSame(1, $r->escalations);
        self::assertCount(5, $esc->dependencyContext[0]->topErrors);
        self::assertCount(5, $esc->groups[0]->frames);
    }

    private function failingAfterTriage(): Analyzer
    {
        $fake = $this->fake;

        return new class ($fake) implements Analyzer {
            public int $triageCalls = 0;

            public function __construct(private readonly FakeAnalyzer $fake)
            {
            }

            public function triage(TriageRequest $req): TriageResult
            {
                ++$this->triageCalls;

                return $this->fake->triage($req);
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                throw new AnalyzerFailed('provider unavailable');
            }
        };
    }

    public function testAnalysisFailureAfterPaidTriageLeavesACheckpointThatAvoidsTriagingAgain(): void
    {
        $this->fake->triage = self::triage(['n' => 'noise', 'i' => 'interesting']);
        $checkpoint = new TriageCheckpoint($this->store, 'svc', 'w');
        $groups = [self::group('n', 10, Level::Warning), self::group('i')];
        $failing = $this->failingAfterTriage();

        try {
            $this->router(analyzer: $failing, checkpoint: $checkpoint)->route($groups, false);
            self::fail('analysis must fail');
        } catch (AnalyzerFailed) {
        }
        self::assertSame(1, $this->fake->triageCalls);

        $this->fake->analysis = self::analysis([self::finding('i')]);
        $r = $this->router(checkpoint: $checkpoint)->route($groups, false);

        self::assertSame(1, $this->fake->triageCalls, 'the retry paid nothing for triage');
        self::assertCount(1, $r->findings);
        self::assertSame('noise', $this->cache->get('n')['triage'] ?? null, 'a reused noise verdict is stored like a fresh one');
        self::assertArrayNotHasKey('triage', $r->usageByStep);
    }

    public function testOnlyTheGroupsTheCheckpointDoesNotCoverAreTriagedOnRetry(): void
    {
        $checkpoint = new TriageCheckpoint($this->store, 'svc', 'w');
        $checkpoint->save(['a' => 'noise'], [self::group('a', 10, Level::Warning)]);
        $this->fake->triage = self::triage(['b' => 'noise']);

        $this->router(checkpoint: $checkpoint)->route([self::group('a', 10, Level::Warning), self::group('b', 10, Level::Warning)], false);

        self::assertSame(['b'], array_map(static fn ($g) => $g->fingerprint, $this->fake->requests[0]->groups));
    }

    public function testASuccessfulAnalysisWritesNoCheckpoint(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding('a')]);

        $this->router(checkpoint: new TriageCheckpoint($this->store, 'svc', 'w'))->route([self::group('a')], false);

        self::assertNull($this->store->get(\Watcher\State\Keys::triage('svc', 'w')));
    }

    public function testEndpointFieldsGoToAnalysisAndEscalationOnlyNeverTriageAndOnlyForTheSentGroups(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'noise']);
        $this->fake->analysisScript = [self::analysis([self::finding('a', Severity::Error, 0.1)]), self::analysis([self::finding('a', Severity::Critical, 0.95)])];
        $ep = ['a' => 'GET /a — Do a', 'b' => 'GET /b — Do b'];

        $this->router()->route([self::group('a'), self::group('b', 10, Level::Warning)], false, [], null, $ep);

        [$tri, $an, $esc] = $this->fake->requests;
        self::assertInstanceOf(TriageRequest::class, $tri);
        self::assertArrayNotHasKey('endpoints', get_object_vars($tri), 'triage requests have no endpoint field at all');
        self::assertInstanceOf(AnalysisRequest::class, $an);
        self::assertSame(['a' => 'GET /a — Do a'], $an->endpoints, 'b was triaged out: its ep is not sent');
        self::assertInstanceOf(AnalysisRequest::class, $esc);
        self::assertSame(['a' => 'GET /a — Do a'], $esc->endpoints);
    }

    public function testEscalationDropsEpFirstWhenItWouldNotFit(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysisScript = [self::analysis([self::finding('a', Severity::Error, 0.1)]), self::analysis([self::finding('a', Severity::Critical, 0.95)])];
        $g = self::group('a');
        $bare = Budget::fixedTokens('analyze', 'ctx', false) + Budget::estimateTokens(CompactPayload::encode([$g]));

        $this->router(escalateMaxInput: $bare)->route([$g], false, [], null, ['a' => 'GET /a — ' . str_repeat('s', 50)]);

        $esc = $this->fake->requests[2];
        self::assertInstanceOf(AnalysisRequest::class, $esc);
        self::assertSame([], $esc->endpoints, 'the escalation is not skipped, the context goes');
        self::assertCount(3, $this->fake->requests);
    }
}
