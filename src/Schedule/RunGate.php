<?php

declare(strict_types=1);

namespace Watcher\Schedule;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Decides whether a host trigger is the scheduled run. The host fires at least hourly; the run is due while
 * `now` is within [run_at, run_at + 1h) local time. Idempotency (one run per window) is the pipeline's job.
 */
final class RunGate
{
    private int $hour;
    private int $minute;

    public function __construct(
        string $runAt,
        private readonly DateTimeZone $timezone,
        private readonly DateInterval|string $window = Window::DEFAULT_DURATION,
    ) {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $runAt, $m) !== 1) {
            throw new InvalidArgumentException("run_at must be HH:MM, got '{$runAt}'.");
        }
        $this->hour = (int) $m[1];
        $this->minute = (int) $m[2];
    }

    public function decide(DateTimeImmutable $now): ?Window
    {
        $local = $now->setTimezone($this->timezone);
        // yesterday too: a late run_at (23:30) stays due after midnight
        foreach ([0, 1] as $daysBack) {
            $runAt = $this->runAtOn($local, -$daysBack);
            if ($now >= $runAt && $now < $runAt->setTimestamp($runAt->getTimestamp() + 3600)) {
                return new Window($this->start($runAt), $runAt);
            }
        }

        return null;
    }

    /** The window ending at the most recent run_at instant <= now, ignoring the due hour (dry run). */
    public function latest(DateTimeImmutable $now): Window
    {
        $local = $now->setTimezone($this->timezone);
        $runAt = $this->runAtOn($local, 0);
        if ($runAt > $now) {
            $runAt = $this->runAtOn($local, -1);
        }

        return new Window($this->start($runAt), $runAt);
    }

    /**
     * Whole-day windows start at the same local wall-clock time N days earlier, so consecutive windows share
     * boundaries across DST changes; sub-day windows are end minus duration.
     */
    private function start(DateTimeImmutable $end): DateTimeImmutable
    {
        $d = is_string($this->window) ? new DateInterval($this->window) : $this->window;
        $wholeDays = $d->y === 0 && $d->m === 0 && $d->i === 0 && $d->s === 0 && $d->f == 0 && $d->h % 24 === 0
            ? $d->d + intdiv($d->h, 24) : 0;
        if ($wholeDays > 0 && $d->invert === 0) {
            return $this->runAtOn($end->setTimezone($this->timezone), -$wholeDays);
        }

        return Window::endingAt($end, $d)->start;
    }

    /** The run_at instant `$days` local days before `$end` (same wall-clock time; DST gaps resolve as for runs). */
    public function endBack(DateTimeImmutable $end, int $days): DateTimeImmutable
    {
        return $this->runAtOn($end, -$days);
    }

    /**
     * The run_at instant on the local date `$days` away from `$ref`'s. A time in the spring-forward gap resolves to
     * the first instant after the gap; an ambiguous (fall-back) time to its first occurrence.
     */
    private function runAtOn(DateTimeImmutable $ref, int $days): DateTimeImmutable
    {
        $date = $ref->setTimezone($this->timezone)->setDate((int) $ref->format('Y'), (int) $ref->format('n'), (int) $ref->format('j') + $days);
        $wall = (new DateTimeImmutable('@0'))->setDate((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'))
            ->setTime($this->hour, $this->minute)->getTimestamp(); // wall-clock digits read as UTC
        $valid = [];
        foreach ([$wall - 86400, $wall + 86400] as $probe) { // offsets in force either side of the date
            $offset = $date->setTimestamp($probe)->getOffset();
            if ($date->setTimestamp($wall - $offset)->getOffset() === $offset) {
                $valid[] = $wall - $offset;
            }
        }
        if ($valid !== []) {
            return $date->setTimestamp(min($valid));
        }
        // Nonexistent: the transition itself is the first valid instant.
        $guess = $wall - $date->setTimestamp($wall - 86400)->getOffset();
        $transitions = $this->timezone->getTransitions($guess - 86400, $guess);

        return $date->setTimestamp($transitions[array_key_last($transitions)]['ts']);
    }
}
