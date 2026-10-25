<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene\Cost;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\Usage;
use Watcher\Config\WatchConfig;
use Watcher\Hygiene\Cost\CostLedger;
use Watcher\Hygiene\Cost\CostReader;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Watcher;

final class CostReaderTest extends TestCase
{
    private const NOW = '2026-10-08T06:30:00+00:00';

    private FrozenClock $clock;
    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable(self::NOW));
        $this->store = new InMemoryStateStore($this->clock);
    }

    /** @param array<string, mixed> $patch */
    private static function config(array $patch = []): WatchConfig
    {
        return WatchConfig::fromArray(array_replace_recursive([
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
                'daily_budget_usd' => 1.0,
                'daily_token_limit' => 100000,
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
        ], $patch));
    }

    private function reader(?WatchConfig $c = null): CostReader
    {
        $c ??= self::config();

        return new CostReader($c, $this->store, new CostLedger($this->store, 'svc', 2592000, new DateTimeZone('UTC'), $this->clock, new CostCalculator([])));
    }

    /** A run record for the window that ended `$daysAgo` days before 2026-10-08T06:00Z. */
    private function runRecord(int $daysAgo, int $in, int $out, float $usd, int $cacheRead = 0, int $cacheWrite = 0): void
    {
        $end = (new DateTimeImmutable('2026-10-08T06:00:00+00:00'))->modify("-$daysAgo day");
        $this->store->put(Keys::run('svc', Watcher::windowKey($end)), [
            'outcome' => 'reported',
            'usage' => ['model' => 'mixed', 'inputTokens' => $in, 'outputTokens' => $out, 'cacheReadTokens' => $cacheRead, 'cacheWriteTokens' => $cacheWrite, 'costUsd' => $usd, 'returnedModel' => null],
            'startedAt' => $end->format(DATE_ATOM), 'finishedAt' => $end->format(DATE_ATOM),
        ], 3600);
    }

    /** @param array<string, int> $groups */
    private function snapshot(int $daysAgo, array $groups): void
    {
        $end = (new DateTimeImmutable('2026-10-08T06:00:00+00:00'))->modify("-$daysAgo day");
        $this->store->put(Keys::snapshot('svc', Watcher::windowKey($end)), ['groups' => $groups, 'totals' => []], 3600);
    }

    public function testEachDayIsTheSumOfItsRunRecordsOldestFirst(): void
    {
        $this->runRecord(0, 1000, 200, 0.05, 300, 100);
        $this->runRecord(2, 400, 100, 0.02);

        $r = $this->reader()->read(new DateTimeImmutable(self::NOW), 3);

        self::assertSame(['2026-10-06', '2026-10-07', '2026-10-08'], array_column($r['days'], 'date'));
        self::assertSame([1, 0, 1], array_column($r['days'], 'runs'));
        self::assertSame([400, 0, 1000], array_column($r['days'], 'tokens_in'));
        self::assertSame([100, 0, 200], array_column($r['days'], 'tokens_out'));
        self::assertSame([0, 0, 300], array_column($r['days'], 'tokens_cached'));
        self::assertSame([500, 0, 1600], array_column($r['days'], 'tokens'), 'total counts cache reads and writes, as the spend counter does');
        self::assertEqualsWithDelta(0.07, $r['total']['cost_usd'], 1e-12);
    }

    public function testUnbookedCallsAreCarriedPerDay(): void
    {
        $this->runRecord(0, 1000, 200, 0.05);
        $ledger = new CostLedger($this->store, 'svc', 2592000, new DateTimeZone('UTC'), $this->clock, new CostCalculator([]));
        $ledger->noteUnbooked();
        $ledger->noteUnbooked();

        $r = $this->reader()->read(new DateTimeImmutable(self::NOW), 2);

        self::assertSame([0, 2], array_column($r['days'], 'unbooked'));
        self::assertSame(2, $r['total']['unbooked']);
    }

    public function testTheTotalEqualsTheSumOfTheRecordedRuns(): void
    {
        $expected = [0, 0.0];
        mt_srand(7);
        for ($d = 0; $d < 14; ++$d) {
            [$in, $out, $usd] = [mt_rand(0, 5000), mt_rand(0, 900), mt_rand(0, 1000) / 10000];
            $this->runRecord($d, $in, $out, $usd);
            $expected[0] += $in + $out;
            $expected[1] += $usd;
        }

        $r = $this->reader()->read(new DateTimeImmutable(self::NOW), 14);

        self::assertSame(14, $r['total']['runs']);
        self::assertSame($expected[0], $r['total']['tokens']);
        self::assertEqualsWithDelta($expected[1], $r['total']['cost_usd'], 1e-9);
    }

    public function testTheShareOfTheDailyCapsIsReported(): void
    {
        $this->runRecord(0, 20000, 5000, 0.25);

        $day = $this->reader()->read(new DateTimeImmutable(self::NOW), 1)['days'][0];

        self::assertSame(['usd' => 0.25, 'tokens' => 0.25], $day['cap_share']);
    }

    public function testWithoutATokenLimitTheTokenShareIsNull(): void
    {
        $c = self::config();
        $this->runRecord(0, 1, 1, 0.1);
        $reader = $this->reader(WatchConfig::fromArray([...self::raw(), 'analysis' => [...self::raw()['analysis'], 'daily_token_limit' => null]]));

        self::assertNull($reader->read(new DateTimeImmutable(self::NOW), 1)['days'][0]['cap_share']['tokens']);
        self::assertNotNull($c);
    }

    /** @return array<string, mixed> */
    private static function raw(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
        ];
    }

    public function testADayAboveTwiceTheMedianOfEarlierDaysIsFlagged(): void
    {
        foreach ([6 => 0.04, 5 => 0.05, 4 => 0.04, 3 => 0.06, 2 => 0.05, 1 => 0.04] as $ago => $usd) {
            $this->runRecord($ago, 1000, 100, $usd);
        }
        $this->runRecord(0, 1000, 100, 0.5);

        $days = $this->reader()->read(new DateTimeImmutable(self::NOW), 7)['days'];

        self::assertSame([false, false, false, false, false, false, true], array_column($days, 'flagged'));
    }

    public function testADayAtTwiceTheMedianIsNotFlaggedAndTheFirstDayHasNothingToCompareWith(): void
    {
        $this->runRecord(2, 1000, 100, 0.10);
        $this->runRecord(1, 1000, 100, 0.10);
        $this->runRecord(0, 1000, 100, 0.20);

        $days = $this->reader()->read(new DateTimeImmutable(self::NOW), 3)['days'];

        self::assertSame([false, false, false], array_column($days, 'flagged'));
    }

    public function testDaysWithoutARunDoNotLowerTheMedian(): void
    {
        $this->runRecord(3, 1000, 100, 0.10);
        $this->runRecord(0, 1000, 100, 0.30);

        $days = $this->reader()->read(new DateTimeImmutable(self::NOW), 4)['days'];

        self::assertTrue($days[3]['flagged']);
    }

    public function testACostSpikeIsExplainedByNewFamiliesGrowthAndEscalations(): void
    {
        foreach ([3, 2, 1] as $ago) {
            $this->runRecord($ago, 1000, 100, 0.05);
        }
        $this->runRecord(0, 9000, 900, 0.9);
        $this->snapshot(1, ['aaaaaaaaaaaa' => 10, 'bbbbbbbbbbbb' => 100]);
        $this->snapshot(0, ['aaaaaaaaaaaa' => 40, 'bbbbbbbbbbbb' => 110, 'cccccccccccc' => 5, 'dddddddddddd' => 6]);
        $ledger = new CostLedger($this->store, 'svc', 2592000, new DateTimeZone('UTC'), $this->clock, new CostCalculator([]));
        $g = new RedactedGroup('aaaaaaaaaaaa', 'Boom', Level::Error, null, [], 40, 10, GroupStatusLabel::Grown, [], new DateTimeImmutable(self::NOW), new DateTimeImmutable(self::NOW));
        $ledger->record('escalate', new Usage('m3', 10, 10), [$g]);

        $day = $this->reader()->read(new DateTimeImmutable(self::NOW), 4)['days'][3];

        self::assertTrue($day['flagged']);
        self::assertSame([['cause' => 'new_families', 'count' => 2], ['cause' => 'grown_families', 'count' => 1], ['cause' => 'escalations', 'count' => 1]], $day['causes']);
    }

    public function testAnUnflaggedDayHasNoCauses(): void
    {
        $this->runRecord(0, 1, 1, 0.1);

        self::assertSame([], $this->reader()->read(new DateTimeImmutable(self::NOW), 1)['days'][0]['causes']);
    }

    public function testCallsAndTopSpendersComeFromTheLedgerAndAreNullWithoutIt(): void
    {
        $this->runRecord(0, 100, 10, 0.01);
        $none = $this->reader()->read(new DateTimeImmutable(self::NOW), 1);
        self::assertFalse($none['ledger']);
        self::assertNull($none['days'][0]['calls']);
        self::assertSame([], $none['top_spenders']);

        $ledger = new CostLedger($this->store, 'svc', 2592000, new DateTimeZone('UTC'), $this->clock, new CostCalculator([]));
        $a = new RedactedGroup('aaaaaaaaaaaa', 'Boom <N>', Level::Error, null, [], 1, null, GroupStatusLabel::New, [], new DateTimeImmutable(self::NOW), new DateTimeImmutable(self::NOW));
        $b = new RedactedGroup('bbbbbbbbbbbb', null, Level::Error, null, [], 1, null, GroupStatusLabel::New, [], new DateTimeImmutable(self::NOW), new DateTimeImmutable(self::NOW));
        $ledger->record('triage', new Usage('m1', 60, 0), [$a, $b]);
        $ledger->record('analyze', new Usage('m2', 100, 0), [$a]);

        $r = $this->reader()->read(new DateTimeImmutable(self::NOW), 1);

        self::assertTrue($r['ledger']);
        self::assertSame(['triage' => 1, 'analyze' => 1, 'escalate' => 0], $r['days'][0]['calls']);
        self::assertSame([
            ['fingerprint' => 'aaaaaaaaaaaa', 'template' => '<w> <N>', 'tokens' => 130, 'runs' => 2],
            ['fingerprint' => 'bbbbbbbbbbbb', 'template' => null, 'tokens' => 30, 'runs' => 1],
        ], $r['top_spenders']);
    }

    public function testTheMedianIsReportedForTheDaysWithARun(): void
    {
        $this->runRecord(2, 1, 1, 0.1);
        $this->runRecord(1, 1, 1, 0.3);
        $this->runRecord(0, 1, 1, 0.2);

        self::assertEqualsWithDelta(0.2, $this->reader()->read(new DateTimeImmutable(self::NOW), 3)['median_cost_usd'], 1e-12);
    }

    public function testTheTemplatesOfTheTopSpendersPassTheSameWordFloorAsEveryOtherReport(): void
    {
        $this->runRecord(0, 1000, 200, 0.05);
        $ledger = new CostLedger($this->store, 'svc', 2592000, new DateTimeZone('UTC'), $this->clock, new CostCalculator(['m' => [1, 1, 1, 1]]));
        $ledger->record('analyze', new Usage('m', 10, 10), [new RedactedGroup('aaaaaaaaaaaa', 'login failed for Ivan Petrenko <N>', Level::Error, null, [], 1, null, GroupStatusLabel::New, [], new DateTimeImmutable(self::NOW), new DateTimeImmutable(self::NOW))]);

        $r = $this->reader()->read(new DateTimeImmutable(self::NOW), 1);

        self::assertSame('login failed for <w> <w> <N>', $r['top_spenders'][0]['template']);
    }
}
