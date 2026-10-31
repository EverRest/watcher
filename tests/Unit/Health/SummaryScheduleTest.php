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
    public function testDefaultIsOnceAtNineEveryDayInTheWatcherTimezone(): void
    {
        $s = self::make();

        self::assertTrue($s->due(new DateTimeImmutable('2026-10-28T07:00:00Z')), '09:00 Kyiv');
        self::assertFalse($s->due(new DateTimeImmutable('2026-10-28T09:00:00Z')), '11:00 Kyiv');
        self::assertTrue($s->due(new DateTimeImmutable('2026-10-31T07:00:00Z')), 'Saturday');
    }

    public function testWorkingDaysAndHoursCanBeSet(): void
    {
        $s = self::make(['summary_days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'summary_hours' => '09-18']);

        self::assertTrue($s->due(new DateTimeImmutable('2026-10-28T07:00:00Z')), 'Wednesday 09:00');
        self::assertFalse($s->due(new DateTimeImmutable('2026-10-31T07:00:00Z')), 'Saturday');
    }

    public function testSummaryHoursOutsideTheWindowMeanNoSummary(): void
    {
        $s = self::make(['summary_at' => '07:00', 'summary_hours' => '09-18']);

        self::assertFalse($s->due(new DateTimeImmutable('2026-10-28T05:00:00Z')), '07:00 Kyiv is before the window');
    }

    public function testSummaryAtNullMeansNoSummary(): void
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
        yield 'bad time' => [['summary_at' => '9:00'], 'summary_at must be HH:MM'];
        yield 'reversed hours' => [['summary_hours' => '18-09'], 'summary_hours must be HH-HH'];
        yield 'hours past 24' => [['summary_hours' => '09-25'], 'summary_hours must be HH-HH'];
        yield 'bad day' => [['summary_days' => ['mon', 'funday']], 'summary_days must be a list'];
        yield 'empty days' => [['summary_days' => []], 'summary_days must be a list'];
    }
}
