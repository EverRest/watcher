<?php

declare(strict_types=1);

namespace Watcher\Report;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** ISO week in UTC (Monday 00:00:00 through the next Monday, exclusive). */
final class Week
{
    public function __construct(
        public readonly string $iso,
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {
    }

    public static function fromIso(string $iso): self
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $iso, $m) !== 1) {
            throw new InvalidArgumentException('week must be YYYY-Www');
        }
        $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setISODate((int) $m[1], (int) $m[2])
            ->setTime(0, 0, 0);

        return new self($iso, $start, $start->modify('+7 days'));
    }

    /** The last complete ISO week before the instant's calendar week (UTC). */
    public static function lastComplete(DateTimeImmutable $now): self
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $dow = (int) $utc->format('N');
        $thisMonday = $utc->setTime(0, 0, 0)->modify('-' . ($dow - 1) . ' days');
        $lastMonday = $thisMonday->modify('-7 days');

        return self::fromDate($lastMonday);
    }

    public static function fromDate(DateTimeImmutable $mondayUtc): self
    {
        $start = $mondayUtc->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0, 0);
        $iso = $start->format('o-\WW');

        return new self($iso, $start, $start->modify('+7 days'));
    }

    public function containsWindowEnd(DateTimeImmutable $windowEnd): bool
    {
        $w = $windowEnd->setTimezone(new DateTimeZone('UTC'));

        return $w >= $this->start && $w < $this->end;
    }

    /** @return array{0: string, 1: string} Mon and Sun dates for the header */
    public function labelDates(): array
    {
        $sun = $this->end->modify('-1 day');

        return [$this->start->format('Y-m-d'), $sun->format('Y-m-d')];
    }
}
