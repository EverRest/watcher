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
        return SummarySchedule::fromConfig($h, new DateTimeZone('Europe/Kyiv'));
    }

    /** 2026-10-28 is a Wednesday, 2026-10-31 a Saturday; Kyiv is UTC+2 on both (DST ended 25 Oct). */
    public function testDefaultIsOnceAtNineOnWeekdaysInTheConfiguredZone(): void
    {
        $s = self::make();

        self::assertTrue($s->due(new DateTimeImmutable('2026-10-28T07:00:00Z')), '09:00 Kyiv');
        self::assertFalse($s->due(new DateTimeImmutable('2026-10-28T09:00:00Z')), '11:00 Kyiv');
        self::assertFalse($s->due(new DateTimeImmutable('2026-10-31T07:00:00Z')), 'Saturday');
    }

    public function testSummaryUsesTheZoneItIsGiven(): void
    {
        $s = SummarySchedule::fromConfig([], new DateTimeZone('Europe/Berlin'));

        self::assertTrue($s->due(new DateTimeImmutable('2026-10-28T08:00:00Z')), '09:00 Berlin (CET, UTC+1)');
        self::assertFalse($s->due(new DateTimeImmutable('2026-10-28T07:00:00Z')), '08:00 Berlin');
    }

    public function testNullSummaryDaysMeansEveryDay(): void
    {
        $s = self::make(['summary_days' => null]);

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

    public function testSummaryAtNullMeansNoSummaryButKeepsTheDayGate(): void
    {
        $s = SummarySchedule::fromConfig(['summary_at' => null], new DateTimeZone('UTC'));

        self::assertFalse($s->due(new DateTimeImmutable('2026-10-28T09:00:00Z')), 'no summary');
        self::assertFalse($s->allowsDay(new DateTimeImmutable('2026-10-31T09:00:00Z')), 'Saturday still gated');
        self::assertTrue($s->allowsDay(new DateTimeImmutable('2026-10-28T09:00:00Z')), 'Wednesday allowed');
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
        yield 'removed key' => [['summary_every_hours' => 1], 'summary_every_hours was removed in 0.13.1'];
        yield 'bad time' => [['summary_at' => '9:00'], 'summary_at must be HH:MM'];
        yield 'reversed hours' => [['summary_hours' => '18-09'], 'summary_hours must be HH-HH'];
        yield 'hours past 24' => [['summary_hours' => '09-25'], 'summary_hours must be HH-HH'];
        yield 'bad day' => [['summary_days' => ['mon', 'funday']], 'summary_days must be a list'];
        yield 'empty days' => [['summary_days' => []], 'summary_days must be a list'];
    }
}
