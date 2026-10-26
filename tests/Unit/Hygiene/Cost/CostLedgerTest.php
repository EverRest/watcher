<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene\Cost;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\Usage;
use Watcher\Hygiene\Cost\CostLedger;
use Watcher\Hygiene\Cost\LedgerAnalyzer;
use Watcher\Level;
use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FailingStateStore;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class CostLedgerTest extends TestCase
{
    private const PRICES = ['m-t' => [1, 5, 0.1, 1.25], 'm-a' => [3, 15, 0.3, 3.75], 'm-e' => [5, 25, 0.5, 6.25]];

    private FrozenClock $clock;
    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00'));
        $this->store = new InMemoryStateStore($this->clock);
    }

    private function ledger(int $maxFamilies = 200, ?\Watcher\State\StateStore $store = null): CostLedger
    {
        return new CostLedger($store ?? $this->store, 'svc', 2592000, new DateTimeZone('UTC'), $this->clock, new CostCalculator(self::PRICES), $maxFamilies);
    }

    /** @return array{calls: array{triage: int, analyze: int, escalate: int}, in: int, out: int, cached: int, usd: float, families: array<string, array{tpl: ?string, tokens: int, runs: int}>, other_tokens: int} */
    private function day(CostLedger $l): array
    {
        $d = $l->day('2026-10-08');
        self::assertNotNull($d);

        return $d;
    }

    private static function group(string $fp, ?string $tpl = 'Boom <N>'): RedactedGroup
    {
        $t = new DateTimeImmutable('2026-10-07T10:00:00+00:00');

        return new RedactedGroup($fp, $tpl, Level::Error, null, [], 5, null, GroupStatusLabel::New, [], $t, $t);
    }

    public function testACallIsCountedPricedAndSplitEvenlyOverItsGroups(): void
    {
        $l = $this->ledger();
        $l->record('triage', new Usage('m-t', 100, 21, 0, 0), [self::group('aaaaaaaaaaaa'), self::group('bbbbbbbbbbbb')]);

        $day = $this->day($l);
        self::assertSame(['triage' => 1, 'analyze' => 0, 'escalate' => 0], $day['calls']);
        self::assertSame([100, 21, 0], [$day['in'], $day['out'], $day['cached']]);
        self::assertEqualsWithDelta((100 * 1 + 21 * 5) / 1_000_000, $day['usd'], 1e-12);
        self::assertSame(['aaaaaaaaaaaa' => ['tpl' => 'Boom <N>', 'tokens' => 61, 'runs' => 1], 'bbbbbbbbbbbb' => ['tpl' => 'Boom <N>', 'tokens' => 60, 'runs' => 1]], $day['families']);
    }

    public function testCallsAccumulateAndCacheTokensCount(): void
    {
        $l = $this->ledger();
        $l->record('analyze', new Usage('m-a', 10, 5, 100, 20), [self::group('aaaaaaaaaaaa')]);
        $l->record('analyze', new Usage('m-a', 10, 5, 0, 0), [self::group('aaaaaaaaaaaa')]);
        $l->record('escalate', new Usage('m-e', 1, 1, 0, 0), [self::group('aaaaaaaaaaaa')]);

        $day = $this->day($l);
        self::assertSame(['triage' => 0, 'analyze' => 2, 'escalate' => 1], $day['calls']);
        self::assertSame(100, $day['cached']);
        self::assertSame(3, $day['families']['aaaaaaaaaaaa']['runs']);
        self::assertSame(135 + 15 + 2, $day['families']['aaaaaaaaaaaa']['tokens']);
    }

    public function testTheDayIsTheServiceLocalDate(): void
    {
        $l = new CostLedger($this->store, 'svc', 2592000, new DateTimeZone('Pacific/Auckland'), $this->clock, new CostCalculator(self::PRICES));
        $l->record('triage', new Usage('m-t', 1, 1), [self::group('aaaaaaaaaaaa')]);

        self::assertNotNull($l->day('2026-10-08'));
        self::assertSame(2592000, $this->store->ttls()[Keys::ledger('svc', '2026-10-08')]);
    }

    public function testTheFamilyTableIsBoundedAndTheDroppedTokensAreKept(): void
    {
        $l = $this->ledger(2);
        foreach ([['aaaaaaaaaaaa', 100], ['bbbbbbbbbbbb', 50], ['cccccccccccc', 10], ['dddddddddddd', 5]] as [$fp, $tokens]) {
            $l->record('analyze', new Usage('m-a', $tokens, 0), [self::group($fp)]);
        }

        $day = $this->day($l);
        self::assertSame(['aaaaaaaaaaaa', 'bbbbbbbbbbbb'], array_keys($day['families']));
        self::assertSame(15, $day['other_tokens']);
    }

    public function testAnUnpricedModelIsBookedAtZero(): void
    {
        $l = $this->ledger();
        $l->record('triage', new Usage('mystery', 10, 10), [self::group('aaaaaaaaaaaa')]);

        self::assertSame(0.0, $this->day($l)['usd']);
    }

    public function testADayWithoutACallHasNoLedger(): void
    {
        self::assertNull($this->ledger()->day('2026-10-08'));
    }

    public function testARecordThatDoesNotValidateIsIgnored(): void
    {
        $this->store->put(Keys::ledger('svc', '2026-10-08'), ['calls' => 'many'], 60);

        self::assertNull($this->ledger()->day('2026-10-08'));
    }

    public function testNoGroupsStillCountsTheCall(): void
    {
        $l = $this->ledger();
        $l->record('triage', new Usage('m-t', 10, 10), []);

        self::assertSame(1, $this->day($l)['calls']['triage']);
        self::assertSame([], $this->day($l)['families']);
    }

    public function testTheDecoratorRecordsAndReturnsTheInnerResultUnchanged(): void
    {
        $triage = new TriageResult(['aaaaaaaaaaaa' => 'noise'], new Usage('m-t', 50, 10));
        $analysis = new AnalysisResult([], new Usage('m-e', 20, 5));
        $inner = new class ($triage, $analysis) implements Analyzer {
            public function __construct(private TriageResult $t, private AnalysisResult $a)
            {
            }

            public function triage(TriageRequest $req): TriageResult
            {
                return $this->t;
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                return $this->a;
            }
        };
        $a = new LedgerAnalyzer($inner, $this->ledger(), 'm-a', 'm-e');

        self::assertSame($triage, $a->triage(new TriageRequest([self::group('aaaaaaaaaaaa')], '', 'm-t', 100)));
        self::assertSame($analysis, $a->analyze(new AnalysisRequest([self::group('aaaaaaaaaaaa')], '', 'm-e', 100)));

        $day = $this->day($this->ledger());
        self::assertSame(['triage' => 1, 'analyze' => 0, 'escalate' => 1], $day['calls']);
    }

    public function testAFailedCallThatWasBilledIsStillRecordedAndRethrown(): void
    {
        $inner = new class () implements Analyzer {
            public function triage(TriageRequest $req): TriageResult
            {
                throw new AnalyzerFailed('bad json', new Usage('m-t', 30, 30));
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                throw new AnalyzerFailed('nothing billed');
            }
        };
        $a = new LedgerAnalyzer($inner, $this->ledger(), 'm-a', 'm-e');

        try {
            $a->triage(new TriageRequest([self::group('aaaaaaaaaaaa')], '', 'm-t', 100));
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed) {
        }
        try {
            $a->analyze(new AnalysisRequest([self::group('aaaaaaaaaaaa')], '', 'm-a', 100));
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed) {
        }

        $day = $this->day($this->ledger());
        self::assertSame(['triage' => 1, 'analyze' => 0, 'escalate' => 0], $day['calls']);
    }

    public function testALedgerWriteFailureNeverLosesAPaidResult(): void
    {
        $failing = new FailingStateStore($this->store);
        $failing->failOn('get');
        $result = new TriageResult([], new Usage('m-t', 1, 1));
        $inner = new class ($result) implements Analyzer {
            public function __construct(private TriageResult $t)
            {
            }

            public function triage(TriageRequest $req): TriageResult
            {
                return $this->t;
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                throw new RuntimeException('unused');
            }
        };
        $a = new LedgerAnalyzer($inner, $this->ledger(store: $failing), 'm-a', 'm-e');

        self::assertSame($result, $a->triage(new TriageRequest([], '', 'm-t', 100)));
    }

    public function testAFailedLedgerWriteLeavesAMarkerAndTheAnalyzerCountsIt(): void
    {
        $probe = new \Watcher\Tests\Fakes\ProbeStateStore($this->store);
        $probe->failPutPrefix = Keys::ledger('svc', '2026-10-08'); // the day record cannot be written; the marker lives under another key
        $result = new TriageResult([], new Usage('m-t', 1, 1));
        $inner = new class ($result) implements Analyzer {
            public function __construct(private TriageResult $t)
            {
            }

            public function triage(TriageRequest $req): TriageResult
            {
                return $this->t;
            }

            public function analyze(AnalysisRequest $req): AnalysisResult
            {
                throw new RuntimeException('unused');
            }
        };
        $ledger = $this->ledger(store: $probe);
        $a = new LedgerAnalyzer($inner, $ledger, 'm-a', 'm-e');

        self::assertSame($result, $a->triage(new TriageRequest([], '', 'm-t', 100)));
        $a->triage(new TriageRequest([], '', 'm-t', 100));

        self::assertSame(2, $a->unbooked(), 'counted in the process');
        self::assertSame(2, $ledger->unbooked('2026-10-08'), 'and left in the state for the cost report');
        self::assertNull($ledger->day('2026-10-08'));
    }
}
