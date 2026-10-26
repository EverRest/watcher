<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Schedule\RunGate;

final class RunGateEveryTest extends TestCase
{
    public function testRunAtListDueWithinHour(): void
    {
        $gate = RunGate::fromSchedule([
            'run_at' => ['06:00', '14:00'],
            'every' => null,
            'timezone' => 'UTC',
            'window' => null,
            'catch_up' => 1,
            'ingestion_delay_seconds' => 0,
            'period_seconds' => 28800,
            'single_daily_run_at' => false,
        ]);
        $now = new DateTimeImmutable('2026-10-25 06:30:00', new DateTimeZone('UTC'));
        self::assertNotNull($gate->decide($now));
        $nowTooEarly = new DateTimeImmutable('2026-10-25 05:30:00', new DateTimeZone('UTC'));
        self::assertNull($gate->decide($nowTooEarly));
    }

    public function testDailyOncePerDayStillWorks(): void
    {
        $gate = RunGate::oncePerDay('06:00', new DateTimeZone('UTC'), 'PT24H');
        $now = new DateTimeImmutable('2026-10-25 06:30:00', new DateTimeZone('UTC'));
        self::assertNotNull($gate->decide($now));
    }
}
