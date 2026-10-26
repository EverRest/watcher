<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\HygieneSettings;
use Watcher\Hygiene\SiteRegistry;

final class SiteRegistryTest extends TestCase
{
    private const MONTH = 2592000;

    private static function now(string $when = '2026-10-08T06:00:00+00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    /** @param array<string, array<string, mixed>> $entries */
    private static function registry(array $entries = [], string $when = '2026-10-08T06:00:00+00:00', ?HygieneSettings $s = null): SiteRegistry
    {
        return new SiteRegistry($entries, self::now($when), $s ?? new HygieneSettings(), self::MONTH);
    }

    /** @return array{id: string, kind: string, count: int, first: DateTimeImmutable} */
    private static function seen(int $count, string $id = 'app/A.php:1', string $first = '2026-10-07T10:00:00+00:00'): array
    {
        return ['id' => $id, 'kind' => 'frame', 'count' => $count, 'first' => new DateTimeImmutable($first)];
    }

    public function testASiteNeverSeenBeforeIsNewAndReportable(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);

        self::assertSame('new', $r->trend('k1'));
        self::assertTrue($r->reportable('k1'));
    }

    public function testASiteSeenButNeverSentStaysNewUntilItIsSent(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);
        $again = self::registry($r->toArray(), '2026-10-09T06:00:00+00:00');
        $again->observe(['k1' => self::seen(10)]);

        self::assertSame('new', $again->trend('k1'));
        self::assertSame('2026-10-07T10:00:00+00:00', $again->toArray()['k1']['first'], 'since when is kept from the first pass');
    }

    public function testAfterItIsSentAnUnchangedSiteIsNotReportedAgainWithinTheRepeatPeriod(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);
        $r->markReported(['k1']);

        $later = self::registry($r->toArray(), '2026-10-12T06:00:00+00:00');
        $later->observe(['k1' => self::seen(11)]);
        self::assertSame('stable', $later->trend('k1'));
        self::assertFalse($later->reportable('k1'));

        $week = self::registry($r->toArray(), '2026-10-15T06:00:00+00:00');
        $week->observe(['k1' => self::seen(11)]);
        self::assertTrue($week->reportable('k1'), 'an unchanged site is repeated once a week');
    }

    public function testASiteThatGrowsPastTheFactorSinceItWasSentIsGrowing(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);
        $r->markReported(['k1']);

        $next = self::registry($r->toArray(), '2026-10-09T06:00:00+00:00');
        $next->observe(['k1' => self::seen(20)]);

        self::assertSame('growing', $next->trend('k1'));
        self::assertTrue($next->reportable('k1'));
    }

    public function testGrowthIsMeasuredAgainstWhatWasLastSentNotAgainstYesterday(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);
        $r->markReported(['k1']);
        $d2 = self::registry($r->toArray(), '2026-10-09T06:00:00+00:00');
        $d2->observe(['k1' => self::seen(15)]);
        $d3 = self::registry($d2->toArray(), '2026-10-10T06:00:00+00:00');
        $d3->observe(['k1' => self::seen(21)]);

        self::assertSame('growing', $d3->trend('k1'));
    }

    /** @param array<string, array<string, mixed>> $entries */
    private static function pass(array $entries, string $when, bool $complete = true, int $repeatDays = 2): SiteRegistry
    {
        $r = self::registry($entries, $when, new HygieneSettings(repeatDays: $repeatDays));
        $r->observe([], $complete);

        return $r;
    }

    public function testASiteIsGoneOnlyAfterTwoCompleteWindowsWithoutItAndThenOnce(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10), 'k2' => self::seen(4, 'app/B.php:2')]);
        $r->markReported(['k1']);

        $one = self::pass($r->toArray(), '2026-10-09T06:00:00+00:00');
        self::assertSame([], $one->gone(), 'one quiet window proves nothing');

        $two = self::pass($one->toArray(), '2026-10-10T06:00:00+00:00');
        self::assertSame(['k1' => 'app/A.php:1'], $two->gone(), 'k2 was never sent, so there is nothing to retract');

        $two->markGoneReported(['k1']);
        self::assertSame([], self::pass($two->toArray(), '2026-10-11T06:00:00+00:00')->gone());
    }

    public function testAWeeklyJobIsNeverGoneOrNewAgainOverFourWeeksOfDailyPasses(): void
    {
        $entries = [];
        $day = new DateTimeImmutable('2026-10-01T06:00:00+00:00');
        for ($i = 0; $i < 28; ++$i) {
            $r = self::registry($entries, $day->modify("+$i days")->format(DATE_ATOM));
            $r->observe($i % 7 === 0 ? ['weekly' => self::seen(10, 'app/Weekly.php:1')] : []);
            if ($i % 7 === 0) {
                self::assertSame($i === 0 ? 'new' : 'stable', $r->trend('weekly'), "day $i");
                $r->markReported(['weekly']);
            }
            self::assertSame([], $r->gone(), "day $i: the weekly site is not gone");
            $entries = $r->toArray();
        }
    }

    public function testASiteIsGoneAfterRepeatDaysOfCompleteQuietDays(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);
        $r->markReported(['k1']);
        $entries = $r->toArray();
        for ($d = 9; $d <= 14; ++$d) {
            $entries = self::pass($entries, sprintf('2026-10-%02dT06:00:00+00:00', $d), repeatDays: 7)->toArray();
            self::assertSame([], self::registry($entries)->gone(), "quiet day $d");
        }

        $seventh = self::pass($entries, '2026-10-15T06:00:00+00:00', repeatDays: 7);
        self::assertSame(['k1' => 'app/A.php:1'], $seventh->gone());
    }

    public function testTwoPassesOnTheSameDayCountAsOneAbsence(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);
        $r->markReported(['k1']);

        $one = self::pass($r->toArray(), '2026-10-09T06:00:00+00:00');
        $again = self::pass($one->toArray(), '2026-10-09T18:00:00+00:00');

        self::assertSame([], $again->gone());
    }

    public function testASampledPassCannotRetractAnything(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);
        $r->markReported(['k1']);

        $one = self::pass($r->toArray(), '2026-10-09T06:00:00+00:00', complete: false);
        $two = self::pass($one->toArray(), '2026-10-10T06:00:00+00:00', complete: false);

        self::assertSame([], $two->gone());
    }

    public function testSeeingTheSiteAgainResetsTheAbsence(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);
        $r->markReported(['k1']);
        $one = self::pass($r->toArray(), '2026-10-09T06:00:00+00:00');
        $back = self::registry($one->toArray(), '2026-10-10T06:00:00+00:00');
        $back->observe(['k1' => self::seen(10)]);
        $again = self::pass($back->toArray(), '2026-10-11T06:00:00+00:00');

        self::assertSame([], $again->gone());
    }

    public function testAnEntryWithAnUnreadableTimeIsDroppedNotFatal(): void
    {
        $r = self::registry(['bad' => ['id' => 'x', 'kind' => 'frame', 'first' => 'not a time', 'last' => '2026-10-08T00:00:00+00:00', 'reportedAt' => 'junk', 'reportedCount' => 1, 'seen' => true, 'gonePending' => false]]);
        $r->observe([]);

        self::assertSame([], $r->toArray());
    }

    public function testASiteThatComesBackIsTrackedAgain(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);
        $r->markReported(['k1']);
        $gone = self::pass(self::pass($r->toArray(), '2026-10-09T06:00:00+00:00')->toArray(), '2026-10-10T06:00:00+00:00');
        $gone->markGoneReported(['k1']);

        $back = self::registry($gone->toArray(), '2026-10-11T06:00:00+00:00');
        $back->observe(['k1' => self::seen(10)]);

        self::assertSame([], $back->gone());
        self::assertSame('stable', $back->trend('k1'));
    }

    public function testOldEntriesAndAnExcessOfEntriesAreDropped(): void
    {
        $r = self::registry(['old' => ['id' => 'x', 'kind' => 'frame', 'first' => '2026-08-01T00:00:00+00:00', 'last' => '2026-08-02T00:00:00+00:00',
            'reportedAt' => null, 'reportedCount' => null, 'seen' => false, 'gonePending' => false, 'absent' => 0, 'absentOn' => null]]);
        $r->observe(['k1' => self::seen(1)]);

        self::assertSame(['k1'], array_keys($r->toArray()));
    }

    public function testTheStoredShapeHoldsNoTextBeyondTheIdentifier(): void
    {
        $r = self::registry();
        $r->observe(['k1' => self::seen(10)]);

        self::assertSame(['id', 'kind', 'first', 'last', 'reportedAt', 'reportedCount', 'seen', 'gonePending', 'absent', 'absentOn', 'goneSentAt'], array_keys($r->toArray()['k1']));
    }

    public function testTheEntryCapKeepsTheMostRecentlySeenAndBreaksTiesByKeyNotByInsertionOrder(): void
    {
        $stored = [];
        foreach (range(0, 2000) as $i) {
            $key = sprintf('k%04d', 2000 - $i); // inserted in descending key order, all seen at the same instant
            $stored[$key] = ['id' => "app/F$i.php:1", 'kind' => 'frame', 'first' => '2026-10-01T06:00:00+00:00', 'last' => '2026-10-07T06:00:00+00:00', 'reportedAt' => null, 'reportedCount' => null, 'seen' => true, 'gonePending' => false, 'absent' => 0, 'absentOn' => null];
        }
        $stored['zzold'] = ['id' => 'app/Old.php:1', 'kind' => 'frame', 'first' => '2026-10-01T06:00:00+00:00', 'last' => '2026-10-06T23:00:00-05:00', 'reportedAt' => null, 'reportedCount' => null, 'seen' => true, 'gonePending' => false, 'absent' => 0, 'absentOn' => null];

        $r = self::registry($stored);
        $r->observe([]);

        $keys = array_keys($r->toArray());
        self::assertCount(2000, $keys);
        self::assertNotContains('k2000', $keys, 'of equal last-seen times the largest key goes first, whatever the order they arrived in');
        self::assertNotContains('zzold', $keys, 'an instant is compared as an instant, not as a string with an offset');
    }
}
