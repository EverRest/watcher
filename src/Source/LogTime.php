<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Throwable;
use Watcher\Schedule\SystemClock;

/** Reads a log timestamp (ISO text or an epoch in s, ms, us or ns) and keeps it inside the plausible range. */
final class LogTime
{
    public function __construct(private readonly ?ClockInterface $clock)
    {
    }

    /**
     * ISO string, or an epoch in seconds, milliseconds, microseconds or nanoseconds (int, float or numeric
     * string; told apart by magnitude). Anything outside 2000-01-01 .. now+1 day is null, never year 55000.
     */
    public function parse(mixed $raw): ?DateTimeImmutable
    {
        try {
            $utc = new DateTimeZone('UTC');
            if (is_string($raw) && is_numeric($raw)) {
                $raw = (float) $raw;
            }
            if (is_int($raw) || is_float($raw)) {
                $n = (float) $raw;
                $seconds = $n / match (true) {
                    abs($n) < 1e11 => 1.0,
                    abs($n) < 1e14 => 1e3,
                    abs($n) < 1e17 => 1e6,
                    default => 1e9,
                };

                return $this->inRange($seconds) ? (new DateTimeImmutable('@' . (int) floor($seconds)))->setTimezone($utc) : null;
            }
            if (is_string($raw) && $raw !== '') {
                $t = (new DateTimeImmutable($raw, $utc))->setTimezone($utc);

                return $this->inRange($t->getTimestamp()) ? $t : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    private function inRange(float|int $seconds): bool
    {
        $now = ($this->clock ?? new SystemClock())->now()->getTimestamp();

        return $seconds >= 946_684_800 && $seconds <= $now + 86_400;
    }
}
