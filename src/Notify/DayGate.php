<?php

declare(strict_types=1);

namespace Watcher\Notify;

use Closure;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends only on the allowed days (notify.days, in the schedule timezone). Outside them the message is dropped and logged:
 * nothing is queued, so it is not sent on the next allowed day. Wrap only the digest and the immediate alerts: failure
 * notices, probes and the heartbeat must still go out on any day.
 */
final class DayGate implements Notifier
{
    private const ISO_DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    /** @param list<string> $days mon..sun */
    public function __construct(
        private readonly Notifier $inner,
        private readonly array $days,
        private readonly DateTimeZone $zone,
        private readonly Closure $now,
        private readonly ?LoggerInterface $log = null,
    ) {
    }

    public function send(Message $message, Destination $to): void
    {
        $local = ($this->now)()->setTimezone($this->zone);
        $allowed = in_array((int) $local->format('N'), array_map(static fn (string $d): int => self::ISO_DAYS[$d], $this->days), true);
        if (!$allowed) {
            $this->log?->info('notify: day not allowed, message dropped');

            return;
        }

        $this->inner->send($message, $to);
    }

    /** @param list<string>|null $days null means every day: the notifier is returned unchanged. */
    public static function wrap(Notifier $inner, ?array $days, DateTimeZone $zone, ClockInterface $clock, ?LoggerInterface $log = null): Notifier
    {
        return $days === null ? $inner : new self($inner, $days, $zone, $clock->now(...), $log);
    }
}
