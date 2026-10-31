<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Health;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Health\SummarySchedule;

final class SummaryScheduleTest extends TestCase
{
    /** @param array<string, mixed> $h */
    private static function make(array $h = []): SummarySchedule
    {
        $s = SummarySchedule::fromConfig($h, new DateTimeZone('Europe/Kyiv'));
        self::assertNotNull($s);

        return $s;
    }

    /** 2026-10-28 is a Wednesday, 2026-10-31 a Saturday; Kyiv is UTC+2 on both (DST ended 25 Oct). */
    public function testDefaultIsOnceAtNineOnWorkingDaysInTheWatcherTimezone(): void
    {
        $s = self::make();

        self::assertTrue($s->due(new DateTimeImmutable('2026-10-28T07:00:00Z')), '09:00 Kyiv');
        self::assertFalse($s->due(new DateTimeImmutable('2026-10-28T09:00:00Z')), '11:00 Kyiv');
        self::assertFalse($s->due(new DateTimeImmutable('2026-10-31T07:00:00Z')), 'Saturday');
    }

    public function testEveryHourRunsOnlyInsideTheWorkingHoursOfWorkingDays(): void
    {
        $s = self::make(['summary_every_hours' => 1]);

        self::assertFalse($s->due(new DateTimeImmutable('2026-10-28T06:00:00Z')), '08:00 before the window');
        self::assertTrue($s->due(new DateTimeImmutable('2026-10-28T07:00:00Z')), '09:00 start');
        self::assertTrue($s->due(new DateTimeImmutable('2026-10-28T15:00:00Z')), '17:00 last hour');
        self::assertFalse($s->due(new DateTimeImmutable('2026-10-28T16:00:00Z')), '18:00 end is exclusive');
        self::assertFalse($s->due(new DateTimeImmutable('2026-10-31T09:00:00Z')), 'Saturday');
    }

    public function testEveryNHoursCountsFromTheStartOfTheWindow(): void
    {
        $s = self::make(['summary_every_hours' => 3]);

        $due = [];
        foreach (range(0, 23) as $h) {
            $due[$h] = $s->due(new DateTimeImmutable(sprintf('2026-10-28T%02d:00:00', $h), new DateTimeZone('Europe/Kyiv')));
        }
        self::assertSame([9, 12, 15], array_keys(array_filter($due)));
    }

    public function testNullWindowAndDaysMeanAroundTheClock(): void
    {
        $s = self::make(['summary_every_hours' => 1, 'summary_hours' => null, 'summary_days' => null]);

        self::assertTrue($s->due(new DateTimeImmutable('2026-10-31T01:00:00Z')), 'Saturday night');
    }

    public function testSummaryAtNullAndNoPeriodMeansNoSummary(): void
    {
        self::assertNull(SummarySchedule::fromConfig(['summary_at' => null], new DateTimeZone('UTC')));
    }

    /**
     * @dataProvider bad
     *
     * @param array<string, mixed> $h
     */
    public function testBadValuesAreRejected(array $h, string $message): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage($message);

        SummarySchedule::fromConfig($h, new DateTimeZone('UTC'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function bad(): iterable
    {
        yield 'zero period' => [['summary_every_hours' => 0], 'summary_every_hours must be 1..24'];
        yield 'string period' => [['summary_every_hours' => '2'], 'summary_every_hours must be 1..24'];
        yield 'both' => [['summary_every_hours' => 2, 'summary_at' => '09:00'], 'cannot both be set'];
        yield 'reversed hours' => [['summary_hours' => '18-09'], 'summary_hours must be HH-HH'];
        yield 'hours past 24' => [['summary_hours' => '09-25'], 'summary_hours must be HH-HH'];
        yield 'bad day' => [['summary_days' => ['mon', 'funday']], 'summary_days must be a list'];
        yield 'empty days' => [['summary_days' => []], 'summary_days must be a list'];
    }
}
