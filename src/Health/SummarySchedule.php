<?php

declare(strict_types=1);

namespace Watcher\Health;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Config\InvalidConfig;

/**
 * When the health summary is due, judged on the hour in the watcher's timezone: at one fixed hour (summary_at), inside
 * the working hours (summary_hours) of the working days (summary_days).
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
    public static function fromConfig(array $h, DateTimeZone $zone): ?self
    {
        $at = array_key_exists('summary_at', $h) ? $h['summary_at'] : '09:00';
        if ($at === null) {
            return null;
        }
        if (!is_string($at) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) !== 1) {
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

        $days = $h['summary_days'] ?? null;
        if ($days !== null && (!is_array($days) || $days === [] || array_diff($days, array_keys(self::DAYS)) !== [])) {
            throw new InvalidConfig('health_check.summary_days must be a list of mon..sun or null');
        }

        return new self($zone, (int) substr($at, 0, 2), $from, $to, array_values(array_map(static fn (string $d): int => self::DAYS[$d], $days ?? [])));
    }

    public function due(DateTimeImmutable $now): bool
    {
        $local = $now->setTimezone($this->zone);
        $hour = (int) $local->format('G');
        if ($hour < $this->from || $hour >= $this->to || ($this->days !== [] && !in_array((int) $local->format('N'), $this->days, true))) {
            return false;
        }

        return $hour === $this->hour;
    }
}
