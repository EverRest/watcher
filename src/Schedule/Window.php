<?php

declare(strict_types=1);

namespace Watcher\Schedule;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/** The half-open time span [start, end) a run examines. Immutable; instants are normalised to UTC. */
final class Window
{
    public const DEFAULT_DURATION = 'PT24H';

    public readonly DateTimeImmutable $start;
    public readonly DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        $utc = new DateTimeZone('UTC');
        $this->start = $start->setTimezone($utc);
        $this->end = $end->setTimezone($utc);

        if ($this->start >= $this->end) {
            throw new InvalidArgumentException('Window start must be strictly before its end.');
        }
    }

    public static function endingAt(DateTimeImmutable $end, DateInterval|string $duration = self::DEFAULT_DURATION): self
    {
        if (is_string($duration)) {
            try {
                $duration = new DateInterval($duration);
            } catch (Throwable $e) {
                throw new InvalidArgumentException("Invalid ISO-8601 duration '{$duration}'.", 0, $e);
            }
        }

        return new self($end->sub($duration), $end);
    }

    public function equals(self $other): bool
    {
        return $this->start == $other->start && $this->end == $other->end;
    }
}
