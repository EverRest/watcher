<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Baseline;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Baseline\BaselineResolver;
use Watcher\Reduce\Fingerprinter;
use Watcher\Reduce\GroupStatus;
use Watcher\Schedule\RunGate;
use Watcher\Schedule\Window;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\HostileValues;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class BaselineResolverTest extends TestCase
{
    private InMemoryStateStore $state;

    protected function setUp(): void
    {
        $this->state = new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-28T06:00:00+00:00')));
    }

    private static function window(string $end = '2026-10-28 06:00', string $tz = 'UTC', string $len = 'PT24H'): Window
    {
        return Window::endingAt(new DateTimeImmutable($end, new DateTimeZone($tz)), $len);
    }

    /**
     * @param array{mode?: string, windows?: int, exclude?: list<string>, since?: ?string} $baseline
     */
    private function resolver(array $baseline, string $tz = 'UTC', string $runAt = '06:00'): BaselineResolver
    {
        $baseline = [
            'mode' => $baseline['mode'] ?? 'previous',
            'windows' => $baseline['windows'] ?? 7,
            'exclude' => $baseline['exclude'] ?? [],
            'since' => $baseline['since'] ?? null,
        ];
        $zone = new DateTimeZone($tz);

        return new BaselineResolver($this->state, RunGate::oncePerDay($runAt, $zone), 'svc', $baseline, $zone, 3.0);
    }

    /** @param array<string, int> $groups */
    private function snap(string $end, array $groups, ?string $start = 'auto'): void
    {
        $e = new DateTimeImmutable($end, new DateTimeZone('UTC'));
        $doc = ['groups' => $groups, 'totals' => [], 'fpv' => Fingerprinter::FPV];
        if ($start === 'auto') {
            $doc['start'] = $e->modify('-24 hours')->format('Y-m-d\TH:i:s\Z');
        } elseif ($start !== null) {
            $doc['start'] = $start;
        }
        $this->state->put(Keys::snapshot('svc', $e->format('Y-m-d\TH:i:s\Z')), $doc, 86400 * 60);
    }

    /** @param list<int|null> $counts one snapshot per day, yesterday first */
    private function days(array $counts, string $fp = 'a'): void
    {
        foreach ($counts as $i => $n) {
            $end = (new DateTimeImmutable('2026-10-28 06:00', new DateTimeZone('UTC')))->modify('-' . ($i + 1) . ' days');
            $this->snap($end->format('Y-m-d H:i'), $n === null ? [] : [$fp => $n]);
        }
    }

    public function testNoHistoryIsABaseline(): void
    {
        $c = $this->resolver(['mode' => 'rolling'])->resolve(self::window(), ['a'], null, null);

        self::assertTrue($c->isBaseline());
        self::assertSame('previous', $c->usedMode);
    }

    public function testPreviousModeReproducesTodaysNumbers(): void
    {
        $prev = ['groups' => ['a' => 10], 'fpv' => Fingerprinter::FPV];
        $c = $this->resolver([])->resolve(self::window(), ['a', 'b'], $prev, new DateTimeImmutable('2026-10-27 06:00 UTC'));

        self::assertFalse($c->isBaseline());
        self::assertSame(GroupStatus::Grown, $c->statusFor('a', 30));
        self::assertSame(GroupStatus::Unchanged, $c->statusFor('a', 29));
        self::assertSame(GroupStatus::New, $c->statusFor('b', 5));
        self::assertSame(10, $c->previousFor('a'));
        self::assertNull($c->previousFor('b'));
        self::assertNull($c->reason);
    }

    public function testSnapshotWithoutStartIsAssumedSameLength(): void
    {
        $this->snap('2026-10-27 06:00', ['a' => 10], null);
        $prev = $this->state->get(Keys::snapshot('svc', '2026-10-27T06:00:00Z'));

        $c = $this->resolver([])->resolve(self::window(), ['a'], $prev, new DateTimeImmutable('2026-10-27 06:00 UTC'));

        self::assertSame(10, $c->previousFor('a'));
        self::assertNull($c->lengthChange);
    }

    public function testScalesToWindowLength(): void
    {
        // yesterday's 24 h window had 24 events; today's window is 12 h long
        $prev = ['groups' => ['a' => 24], 'start' => '2026-10-26T06:00:00Z', 'fpv' => Fingerprinter::FPV];
        $c = $this->resolver([])->resolve(self::window('2026-10-28 06:00', 'UTC', 'PT12H'), ['a'], $prev, new DateTimeImmutable('2026-10-27 06:00 UTC'));

        self::assertSame(12, $c->previousFor('a'));
        self::assertSame(GroupStatus::Unchanged, $c->statusFor('a', 35));
        self::assertSame(GroupStatus::Grown, $c->statusFor('a', 36));
        self::assertSame([24.0, 12.0], $c->lengthChange);
    }

    public function testRollingIsTheMeanOfTheWindowsFound(): void
    {
        $this->days([2, 4, 6, 8, 2, 4, 6]); // mean 32/7
        $c = $this->resolver(['mode' => 'rolling'])->resolve(self::window(), ['a', 'zzz'], null, null);

        self::assertSame('rolling', $c->usedMode);
        self::assertNull($c->reason);
        self::assertCount(7, $c->ends);
        self::assertSame(7, $c->candidates);
        self::assertSame(5, $c->previousFor('a'));
        self::assertSame(GroupStatus::Unchanged, $c->statusFor('a', 13));
        self::assertSame(GroupStatus::Grown, $c->statusFor('a', 14));
        self::assertSame(GroupStatus::New, $c->statusFor('zzz', 1));
    }

    public function testRollingUsesAvailableOrFallsBack(): void
    {
        $this->days([4, 4, 4, 4]);
        $four = $this->resolver(['mode' => 'rolling'])->resolve(self::window(), ['a'], null, null);
        self::assertSame('rolling', $four->usedMode);
        self::assertCount(4, $four->ends);
        self::assertSame(7, $four->candidates);

        $this->setUp();
        $this->days([4]);
        $prev = $this->state->get(Keys::snapshot('svc', '2026-10-27T06:00:00Z'));
        $one = $this->resolver(['mode' => 'rolling'])->resolve(self::window(), ['a'], $prev, new DateTimeImmutable('2026-10-27 06:00 UTC'));
        self::assertSame('previous', $one->usedMode);
        self::assertSame('rolling', $one->requestedMode);
        self::assertSame('short_history', $one->reason);
        self::assertSame(1, $one->found);
        self::assertSame(4, $one->previousFor('a'));
    }

    public function testNeverSeenIsNewInEveryMode(): void
    {
        $this->days([4, 4, 4, 4, 4, 4, 4]);
        foreach (['previous', 'rolling'] as $mode) {
            $prev = $this->state->get(Keys::snapshot('svc', '2026-10-27T06:00:00Z'));
            $c = $this->resolver(['mode' => $mode])->resolve(self::window(), ['never'], $prev, new DateTimeImmutable('2026-10-27 06:00 UTC'));
            self::assertSame(GroupStatus::New, $c->statusFor('never', 500), $mode);
        }
        $this->setUp();
        foreach ([1, 8, 15, 22] as $d) {
            $this->snap((new DateTimeImmutable('2026-10-28 06:00 UTC'))->modify("-{$d} days")->format('Y-m-d H:i'), ['other' => 1]);
        }
        $c = $this->resolver(['mode' => 'weekday', 'windows' => 4])->resolve(self::window(), ['never'], null, null);
        self::assertSame(GroupStatus::New, $c->statusFor('never', 500));
    }

    /** @param list<int|false|null> $counts same-weekday counts, 7 days back first; null = snapshot without the group, false = no snapshot */
    private function weekdays(array $counts): void
    {
        foreach ($counts as $i => $n) {
            if ($n === false) {
                continue;
            }
            $end = (new DateTimeImmutable('2026-10-28 06:00', new DateTimeZone('UTC')))->modify('-' . (($i + 1) * 7) . ' days');
            $this->snap($end->format('Y-m-d H:i'), $n === null ? ['other' => 1] : ['a' => $n]);
        }
    }

    public function testWeekdayIsTheMedianOfTheSameWeekday(): void
    {
        $this->weekdays([10, 20, 30, 40]);
        $c = $this->resolver(['mode' => 'weekday', 'windows' => 4])->resolve(self::window(), ['a'], null, null);

        self::assertSame('weekday', $c->usedMode);
        self::assertSame(25, $c->previousFor('a'));
        self::assertSame('2026-09-30T06:00:00+00:00', $c->ends[0]->format('c'));
        self::assertSame('2026-10-21T06:00:00+00:00', $c->ends[3]->format('c'));
    }

    public function testSeenOnRareDayIsExpected(): void
    {
        $this->weekdays([null, null, null, 5]); // median 0, but seen once
        $c = $this->resolver(['mode' => 'weekday', 'windows' => 4])->resolve(self::window(), ['a'], null, null);

        self::assertSame(GroupStatus::Expected, $c->statusFor('a', 5));
        self::assertSame(GroupStatus::Expected, $c->statusFor('a', 14));
        self::assertSame(GroupStatus::Grown, $c->statusFor('a', 15));
    }

    public function testWeekdayWithTwoSnapshotsFallsBackToPrevious(): void
    {
        $this->weekdays([10, 10]);
        $prev = ['groups' => ['a' => 7], 'fpv' => Fingerprinter::FPV];
        $c = $this->resolver(['mode' => 'weekday', 'windows' => 4])->resolve(self::window(), ['a'], $prev, new DateTimeImmutable('2026-10-27 06:00 UTC'));

        self::assertSame('previous', $c->usedMode);
        self::assertSame('short_history', $c->reason);
        self::assertSame(2, $c->found);
        self::assertSame(7, $c->previousFor('a'));
    }

    public function testMissingSnapshotInTheMiddleIsSkipped(): void
    {
        $this->weekdays([10, false, 30, 50]);
        $c = $this->resolver(['mode' => 'weekday', 'windows' => 4])->resolve(self::window(), ['a'], null, null);

        self::assertCount(3, $c->ends);
        self::assertSame(4, $c->candidates);
        self::assertSame(30, $c->previousFor('a'));
    }

    public function testExcludedDateIsSkipped(): void
    {
        $this->weekdays([10, 1000, 10, 10]); // 14 days back is a holiday
        $c = $this->resolver(['mode' => 'weekday', 'windows' => 4, 'exclude' => ['2026-10-14']])->resolve(self::window(), ['a'], null, null);

        self::assertCount(3, $c->ends);
        self::assertSame(1, $c->excluded);
        self::assertSame(10, $c->previousFor('a'));
    }

    public function testSinceIgnoresOlderWindows(): void
    {
        $this->days([4, 4, 4, 100, 100, 100, 100]);
        $c = $this->resolver(['mode' => 'rolling', 'windows' => 7, 'since' => '2026-10-25'])->resolve(self::window(), ['a'], null, null);

        self::assertCount(3, $c->ends); // ends 27, 26, 25 (local date of the end)
        self::assertSame(4, $c->previousFor('a'));
    }

    public function testRollingIgnoresSnapshotsWithStaleFpVersion(): void
    {
        $end = (new DateTimeImmutable('2026-10-27 06:00', new DateTimeZone('UTC')));
        $this->state->put(Keys::snapshot('svc', $end->format('Y-m-d\TH:i:s\Z')), ['groups' => ['a' => 99], 'totals' => []], 86400 * 60);
        $this->days([4, 4, 4, 4, 4, 4]);

        $c = $this->resolver(['mode' => 'rolling'])->resolve(self::window(), ['a'], null, null);

        self::assertSame('rolling', $c->usedMode);
        self::assertCount(6, $c->ends);
        self::assertSame(4, $c->previousFor('a'));
    }

    public function testWeekdayCandidatesSurviveAClockChange(): void
    {
        // Kyiv autumn change 2026-10-25: 06:00 local is 03:00Z before, 04:00Z after
        foreach (['2026-10-19 03:00', '2026-10-12 03:00', '2026-10-05 03:00'] as $end) {
            $this->snap($end, ['a' => 9]);
        }
        $c = $this->resolver(['mode' => 'weekday', 'windows' => 4], 'Europe/Kyiv')->resolve(self::window('2026-10-26 06:00', 'Europe/Kyiv'), ['a'], null, null);

        self::assertSame('weekday', $c->usedMode);
        self::assertCount(3, $c->ends);
        self::assertSame(9, $c->previousFor('a'));
    }

    public function testCorruptSnapshotIsTreatedAsMissingAndCounted(): void
    {
        $this->days([2, 4, 6, 8, 2, 4, 6]); // mean 32/7
        $this->corrupt(3, 'abc');

        $c = $this->resolver(['mode' => 'rolling'])->resolve(self::window(), ['a'], null, null);

        self::assertSame('rolling', $c->usedMode);
        self::assertSame(6, $c->found); // the day-3 snapshot is skipped, as if it were missing
        self::assertSame(1, $c->unreadable);
    }

    public function testCorruptPreviousSnapshotIsTreatedAsMissingAndCounted(): void
    {
        $prev = ['groups' => ['a' => ['x']], 'totals' => [], 'fpv' => Fingerprinter::FPV];

        $c = $this->resolver(['mode' => 'previous'])->resolve(self::window(), ['a'], $prev, null);

        self::assertTrue($c->isBaseline());
        self::assertSame([], $c->values);
        self::assertSame(1, $c->unreadable);
    }

    /** @return array<string, array{mixed}> */
    public static function hostileValues(): array
    {
        return HostileValues::all();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileValues')]
    public function testHostileStoredValueNeverRaisesAWarningOrATypeError(mixed $value): void
    {
        $this->days([2, 4, 6, 8, 2, 4, 6]);
        $this->corrupt(1, $value);
        $prev = ['groups' => ['a' => $value], 'totals' => [], 'fpv' => Fingerprinter::FPV];

        set_error_handler(static function (int $no, string $message): never {
            throw new \ErrorException($message, 0, $no); // a warning fails the test, as does a TypeError below
        });
        try {
            foreach (['previous', 'rolling', 'weekday'] as $mode) {
                $c = $this->resolver(['mode' => $mode])->resolve(self::window(), ['a'], $prev, null);
                $read = $c->previousFor('a'); // what the run reads back: a value beyond int range must not warn here either
                $this->addToAssertionCount(1);
            }
        } finally {
            restore_error_handler();
        }
    }

    /** Writes a snapshot whose group value is not a count, $daysAgo days before the window end. */
    private function corrupt(int $daysAgo, mixed $value): void
    {
        $end = (new DateTimeImmutable('2026-10-28 06:00', new DateTimeZone('UTC')))->modify('-' . $daysAgo . ' days');
        $doc = ['groups' => ['a' => $value], 'totals' => [], 'fpv' => Fingerprinter::FPV, 'start' => $end->modify('-24 hours')->format('Y-m-d\TH:i:s\Z')];
        $this->state->put(Keys::snapshot('svc', $end->format('Y-m-d\TH:i:s\Z')), $doc, 86400 * 60);
    }
}
