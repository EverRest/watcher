<?php

declare(strict_types=1);

namespace Watcher\Source;

/**
 * Values taken from a log backend's reply. A reader uses a value only when its type and range are usable, and skips and
 * counts an entry (a log line, a series, a bucket) whose value is not. A reply in which more than half the entries are
 * unusable is not a log read: {@see self::check()} fails it as SourceUnavailable('malformed').
 */
final class RemoteValue
{
    /** 2000-01-01 .. 2100-01-01, in epoch seconds: the only range a log timestamp may take. */
    private const MIN_SECONDS = 946_684_800;
    private const MAX_SECONDS = 4_102_444_800;

    /** Above this a count is not a count of log events. */
    private const MAX_COUNT = 1e15;

    /** A Loki nanosecond timestamp (decimal string, or an int), or null when it is not one in the timestamp range. */
    public static function nanos(mixed $raw): ?int
    {
        if (is_int($raw) && $raw >= 0) {
            $raw = (string) $raw;
        }
        // Up to PHP_INT_MAX only: a bigger digit string would be cast with a precision-loss warning.
        if (!is_string($raw) || preg_match('/^\d{1,19}$/D', $raw) !== 1 || (strlen($raw) === 19 && strcmp($raw, '9223372036854775807') > 0)) {
            return null;
        }
        $ns = (int) $raw;

        return self::inRange(intdiv($ns, 1_000_000_000)) ? $ns : null;
    }

    /** Epoch seconds from a JSON number, or null when it is not a number in the timestamp range. */
    public static function seconds(mixed $raw): ?int
    {
        if (!is_int($raw) && !is_float($raw)) {
            return null;
        }
        $n = (float) $raw;
        if (!is_finite($n) || abs($n) > self::MAX_SECONDS) {
            return null;
        }
        $seconds = (int) floor($n);

        return self::inRange($seconds) ? $seconds : null;
    }

    /** A count: a non-negative number or numeric string, at most 10^15. Null for anything else, including "1e400". */
    public static function count(mixed $raw): ?int
    {
        if (is_string($raw) && is_numeric($raw)) {
            $raw = (float) $raw;
        }
        if (!is_int($raw) && !is_float($raw)) {
            return null;
        }
        $n = (float) $raw;
        if (!is_finite($n) || $n < 0 || $n > self::MAX_COUNT) {
            return null;
        }

        return (int) $n;
    }

    /** @throws SourceUnavailable when more than half of the $total entries of a reply were unusable */
    public static function check(int $bad, int $total): void
    {
        if ($bad > 0 && 2 * $bad > $total) {
            throw new SourceUnavailable('malformed');
        }
    }

    private static function inRange(int $seconds): bool
    {
        return $seconds >= self::MIN_SECONDS && $seconds <= self::MAX_SECONDS;
    }
}
