<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source\Kubernetes;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Schedule\Window;
use Watcher\Source\Kubernetes\EventLedger;
use Watcher\Source\Kubernetes\MappedRead;
use Watcher\Source\Kubernetes\Observation;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class EventLedgerTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T00:10:00Z'));
        $this->store = new InMemoryStateStore($this->clock);
    }

    private function ledger(int $tolerance = 120, int $cap = 5000, int $eventTtl = 3600): EventLedger
    {
        return new EventLedger($this->store, 'svc', 86400, $tolerance, $eventTtl, EventLedger::keepSeconds(86400, $tolerance), $cap);
    }

    private static function t(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s);
    }

    private static function obs(string $key, int $count, string $last, string $pod = 'aaaaaaaa', string $reason = 'BackOff', bool $restarted = false): Observation
    {
        return new Observation($key, 'Pod', 'Deployment', 'api', $reason, 'Warning', 'Back-off restarting failed container api', $count, self::t($last)->modify('-5 minutes'), self::t($last), $pod, $restarted);
    }

    /**
     * @param list<Observation> $obs
     * @param list<string> $unattributed
     */
    private function ingest(EventLedger $l, array $obs, string $now, array $unattributed = []): void
    {
        $this->clock->set(self::t($now));
        $l->load();
        $l->merge(new MappedRead($obs, $unattributed, 0), self::t($now));
        $l->save();
    }

    private static function day(): Window
    {
        return new Window(self::t('2026-10-23T06:00:00Z'), self::t('2026-10-24T06:00:00Z'));
    }

    /** @param list<\Watcher\Source\Kubernetes\LedgerEntry> $entries */
    private static function total(array $entries): int
    {
        return array_sum(array_map(static fn ($e): int => $e->count, $entries));
    }

    public function testFirstSightAddsTheWholeCountAndAnUnchangedReadAddsNothing(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [self::obs('k1', 14, '2026-10-23T12:00:00Z')], '2026-10-23T12:05:00Z');
        self::assertSame(14, self::total($l->window(self::day())));

        $this->ingest($l, [self::obs('k1', 14, '2026-10-23T12:00:00Z')], '2026-10-23T13:05:00Z');
        self::assertSame(14, self::total($l->window(self::day())));
    }

    public function testAnIncreaseAddsTheDifferenceAtTheNewTimestamp(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [self::obs('k1', 10, '2026-10-23T12:00:00Z')], '2026-10-23T12:05:00Z');
        $this->ingest($l, [self::obs('k1', 25, '2026-10-23T13:30:00Z')], '2026-10-23T13:35:00Z');

        $e = $l->window(self::day());
        self::assertCount(1, $e);
        self::assertSame(25, $e[0]->count);
        self::assertSame('2026-10-23T13:30:00+00:00', $e[0]->last->format(DATE_ATOM));
    }

    public function testACountResetWithALaterTimestampCountsAsNewOccurrences(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [self::obs('k1', 30, '2026-10-23T12:00:00Z')], '2026-10-23T12:05:00Z');
        $this->ingest($l, [self::obs('k1', 4, '2026-10-23T14:00:00Z')], '2026-10-23T14:05:00Z');

        self::assertSame(34, self::total($l->window(self::day())));
    }

    public function testTwentyFourHourlyReadsEqualTheTrueCount(): void
    {
        $l = $this->ledger();
        $true = 0;
        for ($h = 0; $h < 24; ++$h) {
            $true += 3 + $h % 4;
            $at = (new DateTimeImmutable('2026-10-23T06:20:00Z'))->modify("+$h hours");
            $this->ingest($l, [self::obs('k1', $true, $at->modify('-5 minutes')->format(DATE_ATOM))], $at->format(DATE_ATOM));
        }

        $sum = self::total($l->window(self::day()));
        self::assertSame($true, $sum);
        // the same cluster state read again changes nothing
        $this->ingest($l, [self::obs('k1', $true, '2026-10-24T05:15:00Z')], '2026-10-24T05:50:00Z');
        self::assertSame($true, self::total($l->window(self::day())));
    }

    public function testConsecutiveWindowsPartitionTheEventsByTheToleranceShiftedBoundary(): void
    {
        $l = $this->ledger(120);
        $this->ingest($l, [], '2026-10-23T05:00:00Z'); // an earlier read: the events below are new objects since it
        $this->ingest($l, [
            self::obs('in', 1, '2026-10-24T05:57:59Z'),
            self::obs('late', 2, '2026-10-24T05:58:01Z'), // within the 2 minutes before the end: belongs to the next window
            self::obs('early', 4, '2026-10-23T05:58:30Z'), // within the first 2 minutes of this window's shifted range: this window
        ], '2026-10-24T06:05:00Z');
        $next = new Window(self::t('2026-10-24T06:00:00Z'), self::t('2026-10-25T06:00:00Z'));

        self::assertSame(1 + 4, self::total($l->window(self::day())));
        self::assertSame(2, self::total($l->window($next)));
    }

    public function testEntriesAndIncrementsOlderThanTheKeepWindowArePruned(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [self::obs('old', 5, '2026-10-20T00:00:00Z'), self::obs('new', 1, '2026-10-24T00:00:00Z')], '2026-10-24T00:10:00Z');

        $raw = $this->store->get(Keys::events('svc'));
        self::assertNotNull($raw);
        self::assertSame('', $raw['e']['old']['d'], 'an old increment is dropped; the entry stays as the baseline while it is listed');
        self::assertSame(1, substr_count($raw['e']['new']['d'], ':'));
    }

    public function testAGapLongerThanTheEventTtlIsRecordedAndSurfacedOnlyForItsWindow(): void
    {
        $l = $this->ledger(120, 5000, 3600);
        $this->ingest($l, [self::obs('k1', 1, '2026-10-23T08:55:00Z')], '2026-10-23T09:00:00Z');
        $this->ingest($l, [self::obs('k1', 2, '2026-10-23T12:55:00Z')], '2026-10-23T13:00:00Z'); // four hours later: a gap

        $c = $l->coverage(self::day());
        self::assertCount(1, $c->gaps);
        self::assertSame('2026-10-23T09:00:00+00:00', $c->gaps[0][0]->format(DATE_ATOM));
        self::assertSame('2026-10-23T13:00:00+00:00', $c->gaps[0][1]->format(DATE_ATOM));

        $elsewhere = new Window(self::t('2026-10-25T06:00:00Z'), self::t('2026-10-26T06:00:00Z'));
        self::assertSame([], $l->coverage($elsewhere)->gaps);
    }

    public function testAnObjectThatPredatesTheLedgerIsCreditedOnceNotWithItsLifetimeCount(): void
    {
        $l = $this->ledger();
        // 40 restarts since the pod was created three days ago; the ledger is installed today
        $old = new Observation('p', 'Pod', 'Deployment', 'api', 'ContainerRestarted', 'Warning', 'container restarted', 40, self::t('2026-10-21T00:00:00Z'), self::t('2026-10-23T10:00:00Z'), 'aaaaaaaa', true);
        $this->ingest($l, [$old], '2026-10-23T10:30:00Z');
        self::assertSame(1, self::total($l->window(self::day())));

        $more = new Observation('p', 'Pod', 'Deployment', 'api', 'ContainerRestarted', 'Warning', 'container restarted', 43, self::t('2026-10-21T00:00:00Z'), self::t('2026-10-23T12:00:00Z'), 'aaaaaaaa', true);
        $this->ingest($l, [$more], '2026-10-23T12:05:00Z');
        self::assertSame(1 + 3, self::total($l->window(self::day())));
    }

    public function testAnObjectCreatedAfterTheLastReadIsCreditedInFull(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [], '2026-10-23T10:00:00Z');
        $new = new Observation('n', 'Pod', 'Deployment', 'api', 'BackOff', 'Warning', 't', 12, self::t('2026-10-23T10:20:00Z'), self::t('2026-10-23T10:50:00Z'), 'aaaaaaaa', false);
        $this->ingest($l, [$new], '2026-10-23T11:00:00Z');

        self::assertSame(12, self::total($l->window(self::day())));
    }

    public function testCronJitterIsNotAGap(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [], '2026-10-23T09:00:02Z');
        $this->ingest($l, [], '2026-10-23T10:00:04Z');
        $this->ingest($l, [], '2026-10-23T11:05:00Z'); // five minutes late, still inside the slack

        self::assertSame([], $l->coverage(self::day())->gaps);
    }

    public function testAnEntryStillInTheClusterKeepsItsBaselineAfterItsOwnTimeAgesOut(): void
    {
        $l = $this->ledger();
        // 5 restarts three days ago, seen by every hourly read since
        foreach (['2026-10-21T12:00:00Z', '2026-10-21T13:00:00Z', '2026-10-24T00:00:00Z'] as $now) {
            $this->ingest($l, [self::obs('r', 5, '2026-10-21T11:00:00Z', 'aaaaaaaa', 'ContainerRestarted')], $now);
        }
        $this->ingest($l, [self::obs('r', 6, '2026-10-24T01:30:00Z', 'aaaaaaaa', 'ContainerRestarted')], '2026-10-24T01:35:00Z');

        self::assertSame(1, self::total($l->window(self::day())), 'one new restart, not six');
    }

    public function testAnEntryNoLongerListedIsForgottenAfterTheKeepWindow(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [self::obs('gone', 5, '2026-10-20T11:00:00Z')], '2026-10-20T12:00:00Z');
        $this->ingest($l, [], '2026-10-24T12:00:00Z');

        $raw = $this->store->get(Keys::events('svc'));
        self::assertNotNull($raw);
        self::assertSame([], $raw['e']);
    }

    public function testADryRunHoursAfterTheWindowStillSeesTheWholeWindow(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [self::obs('k', 7, '2026-10-23T07:00:00Z')], '2026-10-23T07:05:00Z');
        $this->ingest($l, [], '2026-10-24T20:00:00Z'); // 14 h after the window ended

        self::assertSame(7, self::total($l->window(self::day())));
    }

    public function testHasDataAfterTheFirstMerge(): void
    {
        $l = $this->ledger();
        self::assertFalse($l->hasData());
        $this->ingest($l, [], '2026-10-23T07:05:00Z');
        self::assertTrue($l->hasData());
    }

    public function testNoGapWhenReadsAreWithinTheEventTtl(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [], '2026-10-23T09:00:00Z');
        $this->ingest($l, [], '2026-10-23T10:00:00Z');

        self::assertSame([], $l->coverage(self::day())->gaps);
    }

    public function testAYoungLedgerIsReportedAsAShortHistory(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [], '2026-10-23T20:00:00Z');

        $c = $l->coverage(self::day());
        self::assertNotNull($c->since);
        self::assertSame('2026-10-23T20:00:00+00:00', $c->since->format(DATE_ATOM));
        self::assertTrue($c->shortHistory);

        $old = $this->ledger();
        $this->ingest($old, [], '2026-10-23T05:00:00Z');
    }

    public function testUnattributedEventsAreCountedOncePerKeyAndWindow(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [], '2026-10-23T09:00:00Z', ['u1', 'u2']);
        $this->ingest($l, [], '2026-10-23T10:00:00Z', ['u1']);

        self::assertSame(2, $l->coverage(self::day())->unattributed);
    }

    public function testEveryPutCarriesAnExpiry(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [self::obs('k1', 1, '2026-10-24T00:00:00Z')], '2026-10-24T00:10:00Z');

        $left = $this->store->ttls();
        self::assertArrayHasKey(Keys::events('svc'), $left);
        self::assertGreaterThan(0, $left[Keys::events('svc')]);
        self::assertLessThanOrEqual(EventLedger::keepSeconds(86400, 120), $left[Keys::events('svc')]);
    }

    public function testThePersistedRecordHoldsOnlyMaskedFingerprintsCountsAndTimes(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [self::obs('k1', 5, '2026-10-24T00:00:00Z', 'a1b2c3d4')], '2026-10-24T00:10:00Z');

        $raw = $this->store->get(Keys::events('svc'));
        self::assertNotNull($raw);
        self::assertSame(['v', 'last_ok', 'since', 'gaps', 'un', 'e', 'capped', 'ovf'], array_keys($raw));
        self::assertSame(['typ', 'rsn', 'wk', 'wl', 'txt', 'pod', 'rs', 'c', 't', 'f', 'seen', 'd'], array_keys($raw['e']['k1']));
        self::assertSame('a1b2c3d4', $raw['e']['k1']['pod']);
    }

    public function testTheEntryCapDropsTheOldestAndSaysSo(): void
    {
        $l = $this->ledger(120, 2);
        $this->ingest($l, [
            self::obs('a', 1, '2026-10-24T00:01:00Z'),
            self::obs('b', 1, '2026-10-24T00:02:00Z'),
            self::obs('c', 1, '2026-10-24T00:03:00Z'),
        ], '2026-10-24T00:10:00Z');

        $raw = $this->store->get(Keys::events('svc'));
        self::assertNotNull($raw);
        self::assertSame(['b', 'c'], array_keys($raw['e']));
        self::assertSame(self::t('2026-10-24T00:10:00Z')->getTimestamp(), $raw['capped']);
        self::assertTrue($l->coverage(self::day())->capped);
        self::assertFalse($l->coverage(new Window(self::t('2026-10-26T06:00:00Z'), self::t('2026-10-27T06:00:00Z')))->capped, 'a cap in one window is not reported for later ones');
    }

    public function testACorruptRecordIsTreatedAsEmpty(): void
    {
        $this->store->put(Keys::events('svc'), ['v' => 99, 'e' => 'nonsense'], 3600);
        $l = $this->ledger();
        $this->ingest($l, [self::obs('k1', 2, '2026-10-24T00:00:00Z')], '2026-10-24T00:10:00Z');

        self::assertSame(2, self::total($l->window(self::day())));
    }

    public function testWindowEntriesCarryTheFieldsTheSourceNeeds(): void
    {
        $l = $this->ledger();
        $this->ingest($l, [self::obs('k1', 7, '2026-10-24T00:00:00Z', 'a1b2c3d4', 'Unhealthy', true)], '2026-10-24T00:10:00Z');

        $e = $l->window(self::day())[0];
        self::assertSame('Warning', $e->type);
        self::assertSame('Unhealthy', $e->reason);
        self::assertSame('Deployment', $e->workloadKind);
        self::assertSame('api', $e->workload);
        self::assertSame('a1b2c3d4', $e->podHash);
        self::assertTrue($e->restarted);
        self::assertSame(7, $e->count);
    }

    // The Redis store refuses a value past these budgets (origin/develop src/State/StateCodec.php: MAX_BYTES, MAX_ELEMENTS, MAX_CONTAINERS;
    // they count every '[' '{' and ',' of the JSON text, punctuation inside strings included). Copied here: lane C has no StateCodec.
    private const CODEC_MAX_BYTES = 4 * 1024 * 1024;
    private const CODEC_MAX_ELEMENTS = 100_000;
    private const CODEC_MAX_CONTAINERS = 16_000;

    /** @return array{int, int, int} bytes, containers, elements of the stored value as the Redis codec counts them */
    private function encodedSize(): array
    {
        $json = json_encode($this->store->get(Keys::events('svc')), JSON_THROW_ON_ERROR);
        $containers = substr_count($json, '[') + substr_count($json, '{');

        return [strlen($json), $containers, $containers + substr_count($json, ',')];
    }

    public function testTheStoredRecordOfAHugeClusterStaysWellUnderTheRedisBudgets(): void
    {
        $l = $this->ledger();
        $base = self::t('2026-10-23T06:00:00Z');
        // 5000 pods, each with an event that changes every hour for a day; texts full of the punctuation the codec counts.
        for ($hour = 0; $hour < 24; ++$hour) {
            $now = $base->modify("+$hour hours")->modify('+5 minutes');
            $obs = [];
            for ($i = 0; $i < 5000; ++$i) {
                $obs[] = new Observation('k' . $i, 'Pod', 'Deployment', 'api', 'BackOff', 'Warning', 'Back-off [restarting], failed {container} a,b,c,d,e', 10 + $hour, $now->modify('-30 minutes'), $now->modify('-5 minutes'), 'aaaa' . $i, false);
            }
            $this->ingest($l, $obs, $now->format(DATE_ATOM));
        }

        [$bytes, $containers, $elements] = $this->encodedSize();

        self::assertLessThanOrEqual(self::CODEC_MAX_BYTES / 2, $bytes);
        self::assertLessThanOrEqual(self::CODEC_MAX_CONTAINERS / 2, $containers);
        self::assertLessThanOrEqual(self::CODEC_MAX_ELEMENTS / 2, $elements);
        $l->load();
        self::assertGreaterThan(0, $l->overflow(), 'what did not fit is counted, never dropped silently');
        self::assertTrue($l->coverage(self::day())->capped);
    }

    public function testTheHourlyPointsOfAnEntryAreOneFlatStringNotACollectionOfArrays(): void
    {
        $l = $this->ledger();
        for ($hour = 0; $hour < 24; ++$hour) {
            $now = self::t('2026-10-23T06:00:00Z')->modify("+$hour hours")->modify('+5 minutes');
            $this->ingest($l, [self::obs('k1', 10 + $hour, $now->modify('-5 minutes')->format(DATE_ATOM))], $now->format(DATE_ATOM));
        }

        [, $containers] = $this->encodedSize();

        self::assertLessThanOrEqual(6, $containers, 'one object per entry plus the few lists of the record, however many points it has');
    }
}
