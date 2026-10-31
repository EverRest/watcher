<?php

declare(strict_types=1);

namespace Watcher\Health;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Config\InvalidConfig;

/**
 * When health messages may be sent, judged in the health check's timezone. allowsDay() gates every health message
 * (alerts and summary) to summary_days. due() is the summary only: summary_at inside summary_hours.
 */
final class SummarySchedule
{
    private const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    /** @param list<int> $days ISO weekdays 1..7; empty = every day */
    public function __construct(
        private readonly DateTimeZone $zone,
        private readonly ?int $hour = null,
        private readonly int $from = 0,
        private readonly int $to = 24,
        private readonly array $days = [],
    ) {
    }

    /**
     * @param array<string, mixed> $h the health_check section
     *
     * @throws InvalidConfig
     */
    public static function fromConfig(array $h, DateTimeZone $zone): self
    {
        // Released in 0.13.0; ignoring it would quietly turn an hourly summary into a daily one.
        if (array_key_exists('summary_every_hours', $h)) {
            throw new InvalidConfig('health_check.summary_every_hours was removed in 0.13.1; use summary_at and summary_hours');
        }
        $at = array_key_exists('summary_at', $h) ? $h['summary_at'] : '09:00';
        if ($at !== null && (!is_string($at) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) !== 1)) {
            throw new InvalidConfig('health_check.summary_at must be HH:MM or null');
        }

        $hours = array_key_exists('summary_hours', $h) ? $h['summary_hours'] : '09-18';
        $from = 0;
        $to = 24;
        if ($hours !== null) {
            if (!is_string($hours) || preg_match('/^(\d{2})-(\d{2})$/', $hours, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 24 || (int) $m[1] >= (int) $m[2]) {
                throw new InvalidConfig('health_check.summary_hours must be HH-HH (start before end, end up to 24) or null');
            }
            [$from, $to] = [(int) $m[1], (int) $m[2]];
        }

        $days = array_key_exists('summary_days', $h) ? $h['summary_days'] : ['mon', 'tue', 'wed', 'thu', 'fri'];
        if ($days !== null && (!is_array($days) || $days === [] || array_diff($days, array_keys(self::DAYS)) !== [])) {
            throw new InvalidConfig('health_check.summary_days must be a list of mon..sun or null');
        }

        return new self($zone, $at === null ? null : (int) substr($at, 0, 2), $from, $to, array_values(array_map(static fn (string $d): int => self::DAYS[$d], $days ?? [])));
    }

    /** Health messages go out only on these days; an empty list means every day. */
    public function allowsDay(DateTimeImmutable $now): bool
    {
        return $this->days === [] || in_array((int) $now->setTimezone($this->zone)->format('N'), $this->days, true);
    }

    /** The working hours of an allowed day: where the hourly problem list may go out. */
    public function inWindow(DateTimeImmutable $now): bool
    {
        $hour = (int) $now->setTimezone($this->zone)->format('G');

        return $hour >= $this->from && $hour < $this->to && $this->allowsDay($now);
    }

    /** The summary is due in its hour, inside the working hours, on an allowed day. Never when summary_at is null. */
    public function due(DateTimeImmutable $now): bool
    {
        $local = $now->setTimezone($this->zone);
        $hour = (int) $local->format('G');

        return $this->hour !== null && $hour === $this->hour && $hour >= $this->from && $hour < $this->to && $this->allowsDay($now);
    }
}
