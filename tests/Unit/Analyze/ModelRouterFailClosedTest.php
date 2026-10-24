<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use DateTimeZone;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Analyze\Budget;
use Watcher\Analyze\BudgetReservation;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\Spend;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\ModelRouter;
use Watcher\Analyze\RouterSettings;
use Watcher\Analyze\Severity;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\Usage;
use Watcher\Analyze\VerdictCache;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\Tests\Fakes\FakeAnalyzer;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Security review 5 (F1, F2, F4 bounds, F5 ranking/escalation, F9): the code, not the model, decides whether a protected group may vanish. */
final class ModelRouterFailClosedTest extends TestCase
{
    private FakeAnalyzer $fake;

    private VerdictCache $cache;

    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->fake = new FakeAnalyzer();
        $this->store = new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00+00:00')));
        $this->cache = new VerdictCache($this->store, 'svc', 2592000);
    }

    private function router(int $topM = 10): ModelRouter
    {
        return new ModelRouter($this->fake, $this->cache, new RouterSettings('m-triage', 'm-analyze', 'm-escalate', 111, 222, 333, $topM, 2, 0.6, 2.0, 'ctx', PHP_INT_MAX, true, false), null);
    }

    /** @param list<string> $watch */
    private static function group(string $fp, int $n = 10, Level $lvl = Level::Error, GroupStatusLabel $st = GroupStatusLabel::New, array $watch = [], ?string $err = null): RedactedGroup
    {
        return new RedactedGroup($fp, 'tpl ' . $fp, $lvl, $err, [], $n, null, $st, $watch, new DateTimeImmutable(), new DateTimeImmutable());
    }

    /** @param array<array-key,string> $v */
    private static function triage(array $v): TriageResult
    {
        return new TriageResult($v, new Usage('tm', 10, 1));
    }

    /** @param list<Finding> $f */
    private static function analysis(array $f): AnalysisResult
    {
        return new AnalysisResult($f, new Usage('am', 100, 10));
    }

    /** @param list<string> $fps */
    private static function finding(array $fps, FindingKind $kind = FindingKind::Bug, Severity $sev = Severity::Error, float $conf = 0.9): Finding
    {
        return new Finding($fps, $kind, $sev, 'T', 'e', 'c', 'a', $conf);
    }

    public function testAnErrorGroupTheAnalysisReportedNothingForIsNotRememberedAndAnalysedAgain(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysis = self::analysis([]);

        $first = $this->router()->route([self::group('a', 500, Level::Critical)], false);

        self::assertNull($this->cache->get('a'), 'not cached for 30 days');
        self::assertSame([], $this->store->ttls(), 'no verdict key at all');
        self::assertSame(1, $first->unreported);

        $second = $this->router()->route([self::group('a', 600, Level::Critical)], false);

        self::assertSame(0, $second->reused, 'never reused silently');
        self::assertSame(2, $this->fake->analyzeCalls);
        self::assertSame(1, $second->unreported);
    }

    public function testAWatchRuleGroupTheAnalysisReportedNothingForIsNotRemembered(): void
    {
        $this->fake->triage = self::triage(['w' => 'interesting']);
        $this->fake->analysis = self::analysis([]);

        $r = $this->router()->route([self::group('w', 10, Level::Info, watch: ['payments'])], false);

        self::assertNull($this->cache->get('w'));
        self::assertSame(1, $r->unreported);
    }

    public function testALowLevelGroupTheAnalysisReportedNothingForIsStillRememberedWithoutCountingAsUnreported(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysis = self::analysis([]);

        $r = $this->router()->route([self::group('a', 10, Level::Warning)], false);

        self::assertSame('interesting', $this->cache->get('a')['triage'] ?? null);
        self::assertSame(0, $r->unreported);
    }

    public function testANoiseOrInfoFindingForAnErrorGroupIsNotRememberedAndIsShownAtLeastAsWarn(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'interesting']);
        $this->fake->analysis = self::analysis([
            self::finding(['a'], FindingKind::Noise, Severity::Info, 0.9),
            self::finding(['b'], FindingKind::Bug, Severity::Info, 0.9),
        ]);

        $r = $this->router()->route([self::group('a', 10, Level::Critical), self::group('b', 10, Level::Error)], false);

        self::assertNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'));
        foreach ($r->findings as $f) {
            self::assertNotSame(FindingKind::Noise, $f->kind, 'a protected group is never "noise"');
            self::assertNotSame(Severity::Info, $f->severity, 'never below warn');
        }
    }

    public function testAnEarlierPoisonedEntryIsNotReusedForAProtectedGroup(): void
    {
        $this->cache->put('a', 'interesting', 500, null);
        $this->cache->put('n', 'interesting', 500, self::finding(['n'], FindingKind::Noise, Severity::Info));

        self::assertFalse($this->cache->isReusable(self::group('a', 600, Level::Critical), 3.0));
        self::assertFalse($this->cache->isReusable(self::group('n', 600, Level::Error), 3.0));
        self::assertTrue($this->cache->isReusable(self::group('a', 600, Level::Warning), 3.0), 'lower levels keep the cheap reuse');
    }

    public function testASubstantiveFindingForAnErrorGroupIsStillRemembered(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding(['a'])]);

        $r = $this->router()->route([self::group('a')], false);

        self::assertNotNull($this->cache->get('a')['finding'] ?? null);
        self::assertSame(0, $r->unreported);
    }

    public function testTriageNoiseKnownOrMissingNeverSilencesAnErrorOrWatchGroup(): void
    {
        $this->fake->triage = self::triage(['e' => 'noise', 'k' => 'known', 'w' => 'noise']); // 'm' is missing
        $this->fake->analysis = self::analysis([]);

        $r = $this->router()->route([
            self::group('e', 10, Level::Error),
            self::group('k', 10, Level::Critical),
            self::group('m', 10, Level::Error),
            self::group('w', 10, Level::Info, watch: ['x']),
        ], false);

        $an = $this->fake->requests[1];
        self::assertInstanceOf(AnalysisRequest::class, $an);
        self::assertEqualsCanonicalizing(['e', 'k', 'm', 'w'], array_map(static fn ($g) => $g->fingerprint, $an->groups));
        self::assertSame(4, $r->triageCoerced);
    }

    public function testEmptyTriageVerdictsDoNotSilenceProtectedGroups(): void
    {
        $this->fake->triage = self::triage([]);
        $this->fake->analysis = self::analysis([self::finding(['e'])]);

        $r = $this->router()->route([self::group('e')], false);

        self::assertSame(1, $this->fake->analyzeCalls);
        self::assertCount(1, $r->findings);
        self::assertSame(1, $r->triageCoerced);
    }

    public function testTriageStillDropsLowLevelGroupsAndCountsThem(): void
    {
        $this->fake->triage = self::triage(['a' => 'noise', 'b' => 'known', 'c' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding(['c'])]);

        $r = $this->router()->route([self::group('a', 10, Level::Warning), self::group('b', 10, Level::Notice), self::group('c', 10, Level::Warning)], false);

        self::assertSame(2, $r->triageDropped);
        self::assertSame(0, $r->triageCoerced);
    }

    public function testFindingSeverityIsBoundedByTheLevelSeenInTheLogsPlusOneStep(): void
    {
        $this->fake->triage = self::triage(['i' => 'interesting', 'w' => 'interesting', 'e' => 'interesting']);
        $this->fake->analysis = self::analysis([
            self::finding(['i'], FindingKind::Anomaly, Severity::Critical),
            self::finding(['w'], FindingKind::Anomaly, Severity::Critical),
            self::finding(['e'], FindingKind::Anomaly, Severity::Critical),
        ]);

        $r = $this->router()->route([self::group('i', 10, Level::Info), self::group('w', 10, Level::Warning), self::group('e', 10, Level::Error)], false);

        $sev = [];
        foreach ($r->findings as $f) {
            $sev[$f->fingerprints[0]] = $f->severity;
        }
        self::assertSame(Severity::Warn, $sev['i']);
        self::assertSame(Severity::Error, $sev['w']);
        self::assertSame(Severity::Critical, $sev['e']);
    }

    public function testEscalationNeedsAnErrorLevelGroupNotJustAModelClaim(): void
    {
        $this->fake->triage = self::triage(['w' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding(['w'], FindingKind::Bug, Severity::Critical, 0.1)]); // "confidence 0.1, critical" on a warning-level group

        $r = $this->router()->route([self::group('w', 10, Level::Warning)], false);

        self::assertSame(0, $r->escalations);
        self::assertSame(1, $this->fake->analyzeCalls);
    }

    public function testNewGroupsAreCappedAtHalfOfTopMEvenWhenTheyOutrankGrownOnes(): void
    {
        // Same level: a minted (new) group never beats a grown one. Higher level: the cap still leaves room for the grown ones.
        $ids = ['n1', 'n2', 'n3', 'n4', 'g1', 'g2'];
        $this->fake->triage = self::triage(array_fill_keys($ids, 'interesting'));
        $this->fake->analysis = self::analysis([]);
        $groups = [
            self::group('n1', 900, Level::Critical, GroupStatusLabel::New),
            self::group('n2', 900, Level::Critical, GroupStatusLabel::New),
            self::group('n3', 900, Level::Critical, GroupStatusLabel::New),
            self::group('n4', 900, Level::Critical, GroupStatusLabel::New),
            self::group('g1', 5, Level::Error, GroupStatusLabel::Grown),
            self::group('g2', 5, Level::Error, GroupStatusLabel::Grown),
        ];

        $r = $this->router(4)->route($groups, false);

        $an = $this->fake->requests[1];
        self::assertInstanceOf(AnalysisRequest::class, $an);
        $sent = array_map(static fn ($g) => $g->fingerprint, $an->groups);
        self::assertContains('g1', $sent);
        self::assertContains('g2', $sent);
        self::assertCount(2, array_filter($sent, static fn (string $fp): bool => $fp[0] === 'n'), 'at most half of top_m for new groups');
        self::assertSame(2, $r->newOverflow);
        self::assertSame(2, $r->droppedByTopM);
    }

    public function testSpareSlotsAreStillGivenToNewGroups(): void
    {
        $this->fake->triage = self::triage(['n1' => 'interesting', 'n2' => 'interesting', 'n3' => 'interesting']);
        $this->fake->analysis = self::analysis([]);

        $r = $this->router(4)->route([self::group('n1'), self::group('n2'), self::group('n3')], false);

        self::assertSame(0, $r->newOverflow);
        self::assertSame(0, $r->droppedByTopM);
    }

    public function testTheTieBreakIsSaltedSoAFingerprintCannotBeGroundToSortFirst(): void
    {
        $run = function (string $salt): array {
            $this->setUp();
            $this->fake->triage = self::triage(array_fill_keys(['a1', 'a2', 'a3', 'a4', 'a5', 'a6'], 'interesting'));
            $this->fake->analysis = self::analysis([]);
            $router = new ModelRouter($this->fake, $this->cache, new RouterSettings('t', 'a', 'e', 1, 1, 1, 1, 2, 0.6, 2.0, '', PHP_INT_MAX, true, false, $salt), null);
            $router->route(array_map(static fn (string $fp): RedactedGroup => self::group($fp, 5, Level::Error, GroupStatusLabel::Grown), ['a1', 'a2', 'a3', 'a4', 'a5', 'a6']), false);

            return array_map(static fn ($g) => $g->fingerprint, $this->fake->requests[1]->groups);
        };

        self::assertSame($run('s1'), $run('s1'), 'deterministic for one salt');
        $winners = [];
        foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $salt) {
            $winners[$run($salt)[0]] = true;
        }
        self::assertGreaterThan(1, count($winners), 'the salt changes who wins a tie');
    }

    public function testAFindingCitingGroupAMustNotAttachANoiseVerdictToUnrelatedGroupB(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding(['a', 'b'], FindingKind::Noise, Severity::Info)]);

        $r = $this->router()->route([self::group('a', 10, Level::Warning, err: 'AException'), self::group('b', 10, Level::Warning, err: 'BException')], false);

        self::assertNotNull($this->cache->get('a'));
        self::assertNull($this->cache->get('b'), 'b was not what the noise finding was about');
        self::assertSame(['a'], $r->findings[0]->fingerprints);
    }

    public function testAWeakFindingMayStillCoverGroupsOfTheSameErrorType(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding(['a', 'b'], FindingKind::Noise, Severity::Info)]);

        $r = $this->router()->route([self::group('a', 10, Level::Warning, err: 'SameException'), self::group('b', 10, Level::Warning, err: 'SameException')], false);

        self::assertSame(['a', 'b'], $r->findings[0]->fingerprints);
    }

    public function testAProtectedGroupDetachedFromAWeakFindingCountsAsUnreported(): void
    {
        $this->fake->triage = self::triage(['a' => 'interesting', 'b' => 'interesting']);
        $this->fake->analysis = self::analysis([self::finding(['a', 'b'], FindingKind::Noise, Severity::Info)]);

        $r = $this->router()->route([self::group('a', 10, Level::Warning), self::group('b', 10, Level::Critical)], false);

        self::assertNull($this->cache->get('b'));
        self::assertSame(1, $r->unreported);
    }

    public function testANewGroupDoesNotOutrankAGrownOneOfTheSameLevel(): void
    {
        $this->fake->triage = self::triage(['n' => 'interesting', 'g' => 'interesting']);
        $this->fake->analysis = self::analysis([]);

        $this->router(1)->route([self::group('n', 900, Level::Error, GroupStatusLabel::New), self::group('g', 5, Level::Error, GroupStatusLabel::Grown)], false);

        self::assertSame(['g'], array_map(static fn ($g) => $g->fingerprint, $this->fake->requests[1]->groups));
    }

    public function testAWatchRuleGroupOutranksEverything(): void
    {
        $this->fake->triage = self::triage(['w' => 'interesting', 'c' => 'interesting']);
        $this->fake->analysis = self::analysis([]);

        $this->router(1)->route([self::group('c', 900, Level::Critical, GroupStatusLabel::Grown), self::group('w', 1, Level::Warning, GroupStatusLabel::New, ['rule'])], false);

        self::assertSame(['w'], array_map(static fn ($g) => $g->fingerprint, $this->fake->requests[1]->groups));
    }

    public function testAStepIsReservedForBothAttemptsNotJustTheFirst(): void
    {
        $g = self::group('a', 10, Level::Warning);
        $in = Budget::fixedTokens('triage', 'ctx', false) + Budget::estimateTokens(CompactPayload::encode([$g]));
        $out = AnthropicAnalyzer::triageOutputCap(111, 1);
        $oneAttempt = ($in + $out) * 1e-4; // $100 per million tokens
        $spend = new Spend($this->store, new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00+00:00')), new DateTimeZone('UTC'), 'svc');
        $reservation = new BudgetReservation($spend, new CostCalculator(['m-triage' => [100, 100, 0, 0], 'm-analyze' => [100, 100, 0, 0], 'm-escalate' => [100, 100, 0, 0]]), $oneAttempt + 1e-6, null);
        $this->fake->triage = self::triage(['a' => 'noise']);

        $r = $this->router()->route([$g], false, [], $reservation);

        self::assertSame(0, $this->fake->triageCalls, 'one attempt would fit, a retry on invalid output would not');
        self::assertSame(1, $r->notTriaged);
        self::assertSame(AnthropicAnalyzer::retryOutputCap(8000), 8192);
        self::assertSame(2000, AnthropicAnalyzer::retryOutputCap(1000));
    }
}
