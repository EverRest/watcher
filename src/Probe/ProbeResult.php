<?php

declare(strict_types=1);

namespace Watcher\Probe;

/**
 * One subject's verdict. `message` never carries a secret value, a DSN, a password or a token: it names the subject and the
 * number of days left. `metric` is the whole days left (negative once expired), for callers that want the number.
 */
final class ProbeResult
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $status,
        public readonly string $subject,
        public readonly string $message,
        public readonly ?int $metric = null,
    ) {
    }

    /**
     * An expiry instant measured in seconds from now: fail when expired or at most $failDays left, warn at most $warnDays left.
     */
    public static function expiry(string $subject, string $what, int $secondsLeft, int $warnDays, int $failDays): self
    {
        if ($secondsLeft <= 0) {
            return new self(self::FAIL, $subject, "$what has expired", (int) floor($secondsLeft / 86400));
        }
        $days = (int) floor($secondsLeft / 86400);
        $status = match (true) {
            $days <= $failDays => self::FAIL,
            $days <= $warnDays => self::WARN,
            default => self::OK,
        };

        return new self($status, $subject, "$what expires in $days days", $days);
    }
}
