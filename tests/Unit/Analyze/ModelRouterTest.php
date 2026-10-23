<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\ModelRouter;
use Watcher\Analyze\RouterSettings;
use Watcher\Analyze\Severity;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\Usage;
use Watcher\Analyze\VerdictCache;
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

    protected function setUp(): void
    {
        $this->fake = new FakeAnalyzer();
        $this->cache = new VerdictCache(new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-23T06:00:00+00:00'))), 'svc', 1000);
    }

    private function router(int $topM = 10, int $cap = 2, bool $cache = true): ModelRouter
    {
        return new ModelRouter($this->fake, $this->cache, new RouterSettings('m-triage', 'm-analyze', 'm-escalate', 111, 222, 333, $topM, $cap, 0.6, 2.0, 'ctx'));
    }

    private static function group(string $fp, int $n = 10, Level $lvl = Level::Error, GroupStatusLabel $st = GroupStatusLabel::New): RedactedGroup
    {
        return new RedactedGroup($fp, 'tpl ' . $fp, $lvl, null, [], $n, null, $st, [], new DateTimeImmutable(), new DateTimeImmutable());
    }

    /** @param array<string,string> $v */
    private static function triage(array $v, string $model = 'tm'): TriageResult
    {
        return new TriageResult($v, [], new Usage($model, 10, 1));
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

    public function testRoutingTable(): void
    {
        $this->fake->triage = self::triage(['a' => 'noise', 'b' => 'known', 'c' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding('c')]);

        $r = $this->router()->route([self::group('a'), self::group('b'), self::group('c')], false);

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
        self::assertSame(['new', 'big', 'mid'], array_map(static fn ($g) => $g->fingerprint, $an->groups));
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

        $this->router()->route([self::group('a')], false);

        self::assertSame(0, $this->fake->analyzeCalls);
    }

    public function testReusedGroupsAreNotSentAnywhere(): void
    {
        $this->cache->put('old', 'known', 10, null);
        $this->fake->triage = self::triage(['new' => 'noise']);

        $r = $this->router()->route([self::group('old', 19), self::group('new')], false);

        self::assertSame(1, $r->reused);
        self::assertSame(1, $r->triaged);
        self::assertSame(['new'], array_map(static fn ($g) => $g->fingerprint, $this->fake->requests[0]->groups));
    }

    public function testAllReusedMeansZeroAnalyzerCalls(): void
    {
        $this->cache->put('old', 'known', 10, null);

        $r = $this->router()->route([self::group('old', 12)], false);

        self::assertSame(0, $this->fake->triageCalls + $this->fake->analyzeCalls);
        self::assertSame(1, $r->reused);
    }

    public function testGrownPastFactorIsRetriaged(): void
    {
        $this->cache->put('old', 'known', 10, null);
        $this->fake->triage = self::triage(['old' => 'noise']);

        $r = $this->router()->route([self::group('old', 20)], false);

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

        $this->router()->route([self::group('a')], false);

        self::assertNotNull($this->cache->get('a'));
        self::assertTrue($this->cache->isReusable(self::group('a', 11), 2.0));
    }
}
