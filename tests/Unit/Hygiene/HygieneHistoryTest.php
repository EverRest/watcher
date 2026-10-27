<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Watcher\Hygiene\DayRecord;
use Watcher\Hygiene\HygieneHistory;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FailingStateStore;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class HygieneHistoryTest extends TestCase
{
    private const RETENTION = 2592000;

    private FrozenClock $clock;
    private InMemoryStateStore $store;
    private HygieneHistory $history;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00'));
        $this->store = new InMemoryStateStore($this->clock);
        $this->history = new HygieneHistory($this->store, 'svc', self::RETENTION, new DateTimeZone('UTC'));
    }

    private static function record(string $date, int $events = 10): DayRecord
    {
        return new DayRecord(
            $date,
            $events,
            false,
            0,
            [['key' => 'k1', 'id' => 'app/A.php:1', 'kind' => 'frame', 'label' => null, 'level' => 'info', 'lines' => 3, 'any_hit' => 2,
                'cats' => ['email' => 2], 'long' => 0, 'trace' => 0, 'mismatch' => 0, 'first' => '2026-10-07T10:00:00+00:00', 'last' => '2026-10-07T11:00:00+00:00']],
            [['fp' => 'a1b2c3d4e5f6', 'tpl' => 'Hello <N>', 'level' => 'info', 'n' => 3, 'first' => '2026-10-07T10:00:00+00:00', 'last' => '2026-10-07T11:00:00+00:00']],
            ['access' => 0, 'json' => 1, 'text' => 2],
            [['tpl' => 'Declined for <*>', 'positions' => 1, 'distinct_messages' => 30]],
        );
    }

    public function testADayRoundTripsAndExpiresWithTheRetention(): void
    {
        $this->history->putDay(self::record('2026-10-07'));

        $back = $this->history->day('2026-10-07');
        self::assertNotNull($back);
        self::assertSame(self::record('2026-10-07')->toArray(), $back->toArray());
        self::assertSame(self::RETENTION, $this->store->ttls()[Keys::hygieneDay('svc', '2026-10-07')]);
    }

    public function testAStoredRecordThatIsNotValidIsIgnoredNotRepaired(): void
    {
        $key = Keys::hygieneDay('svc', '2026-10-07');
        $good = self::record('2026-10-07')->toArray();
        foreach ([
            ['v' => 2] + $good,
            ['events' => 'many'] + $good,
            ['sites' => 'x'] + $good,
            ['families' => [['fp' => 1]]] + $good,
            ['families' => [['fp' => 'a1b2c3d4e5f6', 'tpl' => null, 'level' => 'info', 'n' => 1, 'first' => 'garbage', 'last' => '2026-10-07T10:00:00+00:00']]] + $good,
            [],
        ] as $bad) {
            $this->store->put($key, $bad, 60);
            self::assertNull($this->history->day('2026-10-07'), json_encode($bad, JSON_THROW_ON_ERROR));
        }
    }

    public function testDaysComesBackNewestFirstAndSkipsMissingOnes(): void
    {
        foreach (['2026-10-07', '2026-10-05', '2026-10-01'] as $d) {
            $this->history->putDay(self::record($d));
        }

        $days = $this->history->days(new DateTimeImmutable('2026-10-07T12:00:00+00:00'), 10);

        self::assertSame(['2026-10-07', '2026-10-05', '2026-10-01'], array_keys($days));
        self::assertSame(['2026-10-07'], array_keys($this->history->days(new DateTimeImmutable('2026-10-07T12:00:00+00:00'), 1)));
    }

    public function testTheDayFollowsTheServiceTimezone(): void
    {
        $kyiv = new HygieneHistory($this->store, 'svc', self::RETENTION, new DateTimeZone('Europe/Kyiv'));

        self::assertSame('2026-10-08', $kyiv->dateOf(new DateTimeImmutable('2026-10-07T22:30:00+00:00')));
        self::assertSame('2026-10-07', $this->history->dateOf(new DateTimeImmutable('2026-10-07T22:30:00+00:00')));
    }

    public function testTheSiteRegistryRoundTripsWithTheRetention(): void
    {
        self::assertSame([], $this->history->registry());

        $reg = ['abc' => ['first' => '2026-10-01T00:00:00+00:00', 'last' => '2026-10-07T00:00:00+00:00', 'lastCount' => 5, 'seenLast' => true, 'reportedAt' => null, 'reportedCount' => null]];
        $this->history->putRegistry($reg);

        self::assertSame($reg, $this->history->registry());
        self::assertSame(self::RETENTION, $this->store->ttls()[Keys::hygieneSites('svc')]);
    }

    public function testADeclinedProposalIsRememberedUntilItsDate(): void
    {
        $now = new DateTimeImmutable('2026-10-08T06:00:00+00:00');
        $this->history->decline('p1', $now, 90);

        self::assertTrue($this->history->isDeclined('p1', $now));
        self::assertFalse($this->history->isDeclined('p2', $now));
        self::assertFalse($this->history->isDeclined('p1', $now->modify('+91 days')));
        self::assertGreaterThanOrEqual(90 * 86400, $this->store->ttls()[Keys::hygieneDeclined('svc')]);
    }

    public function testDecliningKeepsEarlierDeclinesAndDropsExpiredOnes(): void
    {
        $now = new DateTimeImmutable('2026-10-08T06:00:00+00:00');
        $this->history->decline('old', $now->modify('-30 days'), 10);
        $this->history->decline('p1', $now, 10);
        $this->history->decline('p2', $now, 20);

        self::assertTrue($this->history->isDeclined('p1', $now));
        self::assertTrue($this->history->isDeclined('p2', $now));
        self::assertSame(['p1', 'p2'], array_keys($this->store->all()[Keys::hygieneDeclined('svc')]));
    }

    public function testAStoreFailureIsNotSwallowed(): void
    {
        $failing = new FailingStateStore($this->store);
        $failing->failOn('get');
        $history = new HygieneHistory($failing, 'svc', self::RETENTION, new DateTimeZone('UTC'));

        $this->expectException(RuntimeException::class);
        $history->day('2026-10-07');
    }

    public function testTheRegistryWriteMergesWithTheStoredOneAndNeverErasesAnotherPassesMarks(): void
    {
        $entry = static fn (array $over = []): array => $over + ['id' => 'app/A.php:1', 'kind' => 'frame', 'first' => '2026-09-20T06:00:00+00:00', 'last' => '2026-10-07T06:00:00+00:00',
            'reportedAt' => null, 'reportedCount' => null, 'seen' => true, 'gonePending' => false, 'absent' => 0, 'absentOn' => null];
        // Pass A read this registry, then pass B (after A lost its lock) reported site s1, cleared a gone mark and found a new site s3.
        $mine = ['s1' => $entry(), 's2' => $entry(['id' => 'app/B.php:1', 'gonePending' => true, 'absent' => 2, 'absentOn' => '2026-10-06'])];
        $this->history->putRegistry([
            's1' => $entry(['reportedAt' => '2026-10-07T07:00:00+00:00', 'reportedCount' => 9]),
            's2' => $entry(['id' => 'app/B.php:1', 'gonePending' => false, 'absent' => 3, 'absentOn' => '2026-10-07', 'reportedAt' => '2026-10-07T07:00:00+00:00', 'reportedCount' => 4]),
            's3' => $entry(['id' => 'app/C.php:1']),
        ]);

        $this->history->putRegistry($mine); // A writes its older copy afterwards

        $r = $this->history->registry();
        self::assertSame('2026-10-07T07:00:00+00:00', $r['s1']['reportedAt'], "B's mark survives");
        self::assertSame(9, $r['s1']['reportedCount']);
        self::assertFalse($r['s2']['gonePending'], "B's cleared gone mark is not set again");
        self::assertSame(2, $r['s2']['absent'], "this pass's own counting is not overwritten by the other's");
        self::assertArrayNotHasKey('s3', $r, 'a site this copy never saw is found again by the next pass; a retracted one is never resurrected');
    }

    public function testAStalePassCannotResurrectARetractionAnotherPassAlreadySent(): void
    {
        $entry = static fn (array $over = []): array => $over + ['id' => 'app/Y.php:1', 'kind' => 'frame', 'first' => '2026-09-20T06:00:00+00:00', 'last' => '2026-10-06T06:00:00+00:00',
            'reportedAt' => '2026-10-01T06:00:00+00:00', 'reportedCount' => 3, 'seen' => false, 'gonePending' => true, 'absent' => 2, 'absentOn' => '2026-10-07', 'goneSentAt' => null];
        // Pass B sent the retraction of Y and cleared it; stale pass A, which read the registry before that, writes its copy afterwards.
        $this->history->putRegistry(['y' => $entry(['gonePending' => false, 'goneSentAt' => '2026-10-08T06:00:00+00:00'])]);

        $this->history->putRegistry(['y' => $entry()]);

        $r = $this->history->registry();
        self::assertFalse($r['y']['gonePending'], 'a retraction that was sent is not pending again');
        self::assertSame('2026-10-08T06:00:00+00:00', $r['y']['goneSentAt']);
    }
}
