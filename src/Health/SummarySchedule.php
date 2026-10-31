<?php

declare(strict_types=1);

namespace Watcher\Health;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Config\InvalidConfig;

/**
 * When the health summary is due, judged on the hour in the watcher's timezone: inside the working hours of a working day,
 * and either at one fixed hour (summary_at) or every N hours counted from the start of the window (summary_every_hours).
 */
final class SummarySchedule
{
    private const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    /** @param list<int> $days ISO weekdays 1..7; empty = every day */
    public function __construct(
        private readonly DateTimeZone $zone,
        private readonly ?int $hour = null,
        private readonly ?int $every = null,
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
        $every = $h['summary_every_hours'] ?? null;
        if ($every !== null && (!is_int($every) || $every < 1 || $every > 24)) {
            throw new InvalidConfig('health_check.summary_every_hours must be 1..24 or null');
        }
        if ($every !== null && array_key_exists('summary_at', $h)) {
            throw new InvalidConfig('health_check.summary_every_hours and health_check.summary_at cannot both be set');
        }
        $at = array_key_exists('summary_at', $h) ? $h['summary_at'] : ($every === null ? '09:00' : null);
        if ($at !== null && (!is_string($at) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) !== 1)) {
            throw new InvalidConfig('health_check.summary_at must be HH:MM or null');
        }
        if ($at === null && $every === null) {
            return null;
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

        $days = $h['summary_days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri'];
        if (array_key_exists('summary_days', $h) && $h['summary_days'] === null) {
            $days = [];
        } elseif (!is_array($days) || $days === [] || array_diff($days, array_keys(self::DAYS)) !== []) {
            throw new InvalidConfig('health_check.summary_days must be a list of mon..sun or null');
        }

        return new self($zone, $at === null ? null : (int) substr($at, 0, 2), $every, $from, $to, array_values(array_map(static fn (string $d): int => self::DAYS[$d], $days)));
    }

    public function due(DateTimeImmutable $now): bool
    {
        $local = $now->setTimezone($this->zone);
        $hour = (int) $local->format('G');
        if ($hour < $this->from || $hour >= $this->to || ($this->days !== [] && !in_array((int) $local->format('N'), $this->days, true))) {
            return false;
        }

        return $this->every !== null ? ($hour - $this->from) % $this->every === 0 : $hour === $this->hour;
    }
}
