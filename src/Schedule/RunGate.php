<?php

declare(strict_types=1);

namespace Watcher\Schedule;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Decides whether a host trigger is due and which window to read. Supports a daily `run_at`, a list of times, or an
 * `every` grid (spec 014). Idempotency (one run per window end) is the pipeline's job.
 */
final class RunGate
{
    private const ALLOWED_EVERY = ['PT1H', 'PT2H', 'PT3H', 'PT4H', 'PT6H', 'PT8H', 'PT12H'];

    /** @param list<string> $runAtList sorted HH:MM; empty when `every` is set */
    public function __construct(
        private readonly DateTimeZone $timezone,
        private readonly ?string $every,
        private readonly array $runAtList,
        private readonly ?string $explicitWindow,
        private readonly bool $singleDailyRunAt,
        private readonly int $periodSeconds,
        private readonly int $catchUp,
        private readonly int $ingestionDelaySeconds,
    ) {
    }

    /** Legacy constructor shape used across the existing suite (one `run_at`, default `PT24H` window). */
    public static function oncePerDay(string $runAt, DateTimeZone $timezone, DateInterval|string $window = Window::DEFAULT_DURATION): self
    {
        $w = is_string($window) ? $window : self::intervalToSpec($window);

        return self::fromSchedule([
            'run_at' => [$runAt],
            'every' => null,
            'timezone' => $timezone->getName(),
            'window' => $w,
            'catch_up' => 1,
            'ingestion_delay_seconds' => 0,
            'period_seconds' => 86400,
            'single_daily_run_at' => true,
        ], $timezone);
    }

    /** @param array<string, mixed> $schedule validated `WatchConfig::$schedule` */
    public static function fromSchedule(array $schedule, ?DateTimeZone $timezone = null): self
    {
        $tz = $timezone ?? new DateTimeZone((string) $schedule['timezone']);

        return new self(
            $tz,
            $schedule['every'] ?? null,
            $schedule['run_at'] ?? [],
            $schedule['window'] ?? null,
            ($schedule['single_daily_run_at'] ?? false) === true,
            (int) ($schedule['period_seconds'] ?? 86400),
            (int) ($schedule['catch_up'] ?? 1),
            (int) ($schedule['ingestion_delay_seconds'] ?? 0),
        );
    }

    /**
     * Up to `catch_up` windows whose boundary is due at `$now`, newest boundary first (done-check is the caller's job).
     *
     * @return list<Window>
     */
    public function pending(DateTimeImmutable $now): array
    {
        $candidates = [];
        foreach ($this->dueBoundaries($now) as $boundary) {
            $prev = $this->previousBoundary($boundary);
            if ($prev === null) {
                continue;
            }
            $candidates[] = $this->windowBetween($prev, $boundary);
        }
        if ($candidates === []) {
            return [];
        }
        usort($candidates, static fn (Window $a, Window $b): int => $b->end <=> $a->end);

        return array_slice($candidates, 0, $this->catchUp);
    }

    public function decide(DateTimeImmutable $now): ?Window
    {
        $pending = $this->pending($now);

        return $pending[0] ?? null;
    }

    /** The window ending at the most recent boundary <= now, ignoring the due period (dry run). */
    public function latest(DateTimeImmutable $now): Window
    {
        $boundary = $this->latestBoundary($now);
        $prev = $this->previousBoundary($boundary);
        if ($prev === null) {
            throw new InvalidArgumentException('No boundary before the latest one.');
        }

        return $this->windowBetween($prev, $boundary);
    }

    /**
     * The boundary instant `$days` local days before `$end`'s wall-clock slot (DST-safe; list mode uses the first run_at).
     */
    public function endBack(DateTimeImmutable $end, int $days): DateTimeImmutable
    {
        if ($this->every !== null) {
            $steps = intdiv($days * 86400, $this->periodSeconds);

            return $this->boundaryBack($end, $steps);
        }
        $slot = $this->runAtList[0] ?? '00:00';

        return $this->instantOnLocalDate(
            $this->localDate($end->setTimezone($this->timezone))->modify("-{$days} days"),
            $slot,
        );
    }

    /**
     * @return list<DateTimeImmutable> boundaries due at `$now`, newest first
     */
    private function dueBoundaries(DateTimeImmutable $now): array
    {
        $out = [];
        foreach ($this->boundariesUpTo($now) as $boundary) {
            if ($this->isDue($now, $boundary)) {
                $out[] = $boundary;
            }
        }

        return array_reverse($out);
    }

    private function isDue(DateTimeImmutable $now, DateTimeImmutable $boundary): bool
    {
        if ($now < $boundary) {
            return false;
        }
        if ($this->every !== null) {
            $next = $boundary->add(new DateInterval('PT' . $this->periodSeconds . 'S'));

            return $now < $next;
        }
        $hourEnd = $boundary->add(new DateInterval('PT1H'));

        return $now < $hourEnd;
    }

    /** @return list<DateTimeImmutable> ascending */
    private function boundariesUpTo(DateTimeImmutable $now): array
    {
        $local = $now->setTimezone($this->timezone);
        $days = [$local->modify('-1 day'), $local];
        $out = [];
        foreach ($days as $day) {
            foreach ($this->boundariesOnLocalDate($day) as $b) {
                if ($b <= $now) {
                    $out[$b->format('U.u')] = $b;
                }
            }
        }
        ksort($out);

        return array_values($out);
    }

    private function latestBoundary(DateTimeImmutable $now): DateTimeImmutable
    {
        $all = $this->boundariesUpTo($now);
        if ($all === []) {
            throw new InvalidArgumentException('No boundary on or before now.');
        }

        return $all[array_key_last($all)];
    }

    /** @return list<DateTimeImmutable> ascending within the local calendar date */
    private function boundariesOnLocalDate(DateTimeImmutable $localRef): array
    {
        $date = $this->localDate($localRef);
        if ($this->every !== null) {
            $midnight = $date->setTime(0, 0);
            $endOfDay = $date->modify('+1 day');
            $out = [];
            $cursor = $midnight;
            while ($cursor < $endOfDay && $this->localDate($cursor) === $date) {
                $out[] = $cursor;
                $next = $cursor->add(new DateInterval('PT' . $this->periodSeconds . 'S'));
                if ($next <= $cursor) {
                    break;
                }
                $cursor = $next;
            }

            return $out;
        }

        $out = [];
        foreach ($this->runAtList as $time) {
            $out[] = $this->instantOnLocalDate($date, $time);
        }
        usort($out, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $out;
    }

    private function previousBoundary(DateTimeImmutable $boundary): ?DateTimeImmutable
    {
        $local = $boundary->setTimezone($this->timezone);
        $all = [];
        foreach ([$local->modify('-1 day'), $local] as $day) {
            foreach ($this->boundariesOnLocalDate($day) as $b) {
                if ($b < $boundary) {
                    $all[$b->format('U.u')] = $b;
                }
            }
        }
        if ($all === []) {
            return null;
        }
        ksort($all);

        return $all[array_key_last($all)];
    }

    private function boundaryBack(DateTimeImmutable $end, int $steps): DateTimeImmutable
    {
        $cursor = $end;
        for ($i = 0; $i < $steps; ++$i) {
            $prev = $this->previousBoundary($cursor);
            if ($prev === null) {
                break;
            }
            $cursor = $prev;
        }

        return $cursor;
    }

    private function windowBetween(DateTimeImmutable $prevBoundary, DateTimeImmutable $endBoundary): Window
    {
        $end = $endBoundary->sub(new DateInterval('PT' . $this->ingestionDelaySeconds . 'S'));
        $startBoundary = $prevBoundary->sub(new DateInterval('PT' . $this->ingestionDelaySeconds . 'S'));
        if ($this->explicitWindow !== null && ($this->singleDailyRunAt || $this->every === null)) {
            return $this->windowWithExplicitDuration($end, $this->explicitWindow);
        }

        return new Window($startBoundary, $end);
    }

    private function windowWithExplicitDuration(DateTimeImmutable $end, string $window): Window
    {
        $d = new DateInterval($window);
        $wholeDays = $d->y === 0 && $d->m === 0 && $d->i === 0 && $d->s === 0 && $d->f == 0 && $d->h % 24 === 0
            ? $d->d + intdiv($d->h, 24) : 0;
        if ($wholeDays > 0 && $d->invert === 0 && $this->runAtList !== []) {
            $slot = $this->runAtList[0];
            $localEnd = $end->setTimezone($this->timezone);
            $start = $this->instantOnLocalDate($this->localDate($localEnd)->modify("-{$wholeDays} days"), $slot);

            return new Window($start, $end);
        }

        return Window::endingAt($end, $d);
    }

    private function instantOnLocalDate(DateTimeImmutable $localDate, string $hhmm): DateTimeImmutable
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $hhmm, $m) !== 1) {
            throw new InvalidArgumentException("run_at must be HH:MM, got '{$hhmm}'.");
        }

        return $this->runAtOn($localDate, (int) $m[1], (int) $m[2]);
    }

    private function localDate(DateTimeImmutable $local): DateTimeImmutable
    {
        return $local->setTime(0, 0);
    }

    /**
     * Wall-clock time on `$ref`'s local date. A nonexistent time resolves to the first instant after the gap; an
     * ambiguous time to its first occurrence.
     */
    private function runAtOn(DateTimeImmutable $ref, int $hour, int $minute): DateTimeImmutable
    {
        $date = $ref->setTimezone($this->timezone);
        $wall = (new DateTimeImmutable('@0'))->setDate((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'))
            ->setTime($hour, $minute)->getTimestamp();
        $valid = [];
        foreach ([$wall - 86400, $wall + 86400] as $probe) {
            $offset = $date->setTimestamp($probe)->getOffset();
            if ($date->setTimestamp($wall - $offset)->getOffset() === $offset) {
                $valid[] = $wall - $offset;
            }
        }
        if ($valid !== []) {
            return $date->setTimestamp(min($valid));
        }
        $guess = $wall - $date->setTimestamp($wall - 86400)->getOffset();
        $transitions = $this->timezone->getTransitions($guess - 86400, $guess);
        $last = end($transitions);

        return $date->setTimestamp($last === false ? $guess : $last['ts']);
    }

    private static function intervalToSpec(DateInterval $interval): string
    {
        $parts = [];
        if ($interval->y > 0) {
            $parts[] = $interval->y . 'Y';
        }
        if ($interval->m > 0) {
            $parts[] = $interval->m . 'M';
        }
        if ($interval->d > 0) {
            $parts[] = $interval->d . 'D';
        }
        $time = '';
        if ($interval->h > 0) {
            $time .= $interval->h . 'H';
        }
        if ($interval->i > 0) {
            $time .= $interval->i . 'M';
        }
        if ($interval->s > 0) {
            $time .= $interval->s . 'S';
        }
        if ($time !== '') {
            $parts[] = 'T' . $time;
        }

        return 'P' . implode('', $parts);
    }

    /**
     * @return list<string>
     *
     * @internal
     */
    public static function allowedEvery(): array
    {
        return self::ALLOWED_EVERY;
    }
}
