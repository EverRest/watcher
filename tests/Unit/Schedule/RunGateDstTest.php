<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Watcher\Schedule\RunGate;
use Watcher\Schedule\Window;

final class RunGateDstTest extends TestCase
{
    private function hourlyKyiv(): RunGate
    {
        return RunGate::fromSchedule([
            'run_at' => [],
            'every' => 'PT1H',
            'timezone' => 'Europe/Kyiv',
            'window' => null,
            'catch_up' => 1,
            'ingestion_delay_seconds' => 0,
            'period_seconds' => 3600,
            'single_daily_run_at' => false,
        ]);
    }

    /** @return list<Window> one window per boundary on the local calendar date */
    private function windowsOnLocalDate(RunGate $gate, DateTimeZone $tz, string $localDate): array
    {
        $ref = new ReflectionClass($gate);
        $boundariesOn = $ref->getMethod('boundariesOnLocalDate');
        $prevBoundary = $ref->getMethod('previousBoundary');
        $windowBetween = $ref->getMethod('windowBetween');
        $day = new DateTimeImmutable($localDate, $tz);
        $windows = [];
        foreach ($boundariesOn->invoke($gate, $day) as $boundary) {
            $prev = $prevBoundary->invoke($gate, $boundary);
            if ($prev !== null) {
                $windows[] = $windowBetween->invoke($gate, $prev, $boundary);
            }
        }

        return $windows;
    }

    /** @param list<Window> $windows */
    private function assertContiguous(array $windows): void
    {
        for ($i = 1, $n = count($windows); $i < $n; ++$i) {
            self::assertSame(
                $windows[$i - 1]->end->format('U'),
                $windows[$i]->start->format('U'),
                "window {$i} start must equal previous end",
            );
        }
    }

    /** @param list<Window> $windows */
    private function assertUniqueBoundaries(array $windows): void
    {
        $ends = array_map(static fn (Window $w): string => $w->end->format('U'), $windows);
        self::assertSame(count($ends), count(array_unique($ends)), 'each boundary end appears once');
    }

    public function testKyivSpringHourly(): void
    {
        $tz = new DateTimeZone('Europe/Kyiv');
        $windows = $this->windowsOnLocalDate($this->hourlyKyiv(), $tz, '2026-03-29');
        self::assertCount(23, $windows);
        $this->assertContiguous($windows);
        $this->assertUniqueBoundaries($windows);
        $fourAm = null;
        foreach ($windows as $w) {
            if ($w->end->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('H:i') === '04:00') {
                $fourAm = $w;
                break;
            }
        }
        self::assertNotNull($fourAm);
        self::assertSame(3600, $fourAm->end->getTimestamp() - $fourAm->start->getTimestamp());
    }

    public function testKyivAutumnHourly(): void
    {
        $tz = new DateTimeZone('Europe/Kyiv');
        $windows = $this->windowsOnLocalDate($this->hourlyKyiv(), $tz, '2026-10-25');
        self::assertCount(24, $windows);
        $this->assertContiguous($windows);
        $this->assertUniqueBoundaries($windows);
        $fourAm = null;
        foreach ($windows as $w) {
            if ($w->end->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('H:i') === '04:00') {
                $fourAm = $w;
                break;
            }
        }
        self::assertNotNull($fourAm);
        self::assertSame(7200, $fourAm->end->getTimestamp() - $fourAm->start->getTimestamp());
    }

    public function testKyivSpringTwoAndThreeHourly(): void
    {
        $two = RunGate::fromSchedule([
            'run_at' => [],
            'every' => 'PT2H',
            'timezone' => 'Europe/Kyiv',
            'window' => null,
            'catch_up' => 24,
            'ingestion_delay_seconds' => 0,
            'period_seconds' => 7200,
            'single_daily_run_at' => false,
        ]);
        $tz = new DateTimeZone('Europe/Kyiv');
        $dayWindows = $this->windowsOnLocalDate($two, $tz, '2026-03-29');
        self::assertCount(12, $dayWindows);
        self::assertCount(1, array_filter($dayWindows, static fn (Window $w): bool => $w->end->getTimestamp() - $w->start->getTimestamp() === 3600));

        $three = RunGate::fromSchedule([
            'run_at' => [],
            'every' => 'PT3H',
            'timezone' => 'Europe/Kyiv',
            'window' => null,
            'catch_up' => 24,
            'ingestion_delay_seconds' => 0,
            'period_seconds' => 10800,
            'single_daily_run_at' => false,
        ]);
        $day3 = $this->windowsOnLocalDate($three, $tz, '2026-03-29');
        self::assertCount(7, $day3);
        $sixAm = null;
        foreach ($day3 as $w) {
            if ($w->end->setTimezone($tz)->format('H:i') === '06:00') {
                $sixAm = $w;
                break;
            }
        }
        self::assertNotNull($sixAm);
        self::assertSame(5 * 3600, $sixAm->end->getTimestamp() - $sixAm->start->getTimestamp());
    }

    private function hourlyNewYork(): RunGate
    {
        return RunGate::fromSchedule([
            'run_at' => [],
            'every' => 'PT1H',
            'timezone' => 'America/New_York',
            'window' => null,
            'catch_up' => 1,
            'ingestion_delay_seconds' => 0,
            'period_seconds' => 3600,
            'single_daily_run_at' => false,
        ]);
    }

    public function testNewYorkSpringHourly(): void
    {
        $tz = new DateTimeZone('America/New_York');
        $windows = $this->windowsOnLocalDate($this->hourlyNewYork(), $tz, '2026-03-08');
        self::assertCount(23, $windows);
        $this->assertContiguous($windows);
        $this->assertUniqueBoundaries($windows);
    }

    public function testNewYorkAutumnHourly(): void
    {
        $tz = new DateTimeZone('America/New_York');
        $windows = $this->windowsOnLocalDate($this->hourlyNewYork(), $tz, '2026-11-01');
        self::assertCount(24, $windows);
        $this->assertContiguous($windows);
        $this->assertUniqueBoundaries($windows);
        $twoHour = null;
        $tz = new DateTimeZone('America/New_York');
        foreach ($windows as $w) {
            $len = $w->end->getTimestamp() - $w->start->getTimestamp();
            if ($len === 7200) {
                $twoHour = $w;
                break;
            }
        }
        self::assertNotNull($twoHour);
        self::assertSame('02:00', $twoHour->end->setTimezone($tz)->format('H:i'));
    }
}
