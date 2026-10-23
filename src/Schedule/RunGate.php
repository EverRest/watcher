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
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $runAt, $m) !== 1) {
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
            $runAt = $local->modify("-{$daysBack} day")->setTime($this->hour, $this->minute);
            if ($now >= $runAt && $now < $runAt->modify('+1 hour')) {
                return Window::endingAt($runAt, $this->window);
            }
        }

        return null;
    }
}
