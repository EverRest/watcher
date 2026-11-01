<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;

/** Validates the `schedule` section and returns the normalised schedule with its interval. */
final class ScheduleSection
{
    /**
     * @param array<string, mixed> $sch
     * @return array{0: array<string, mixed>, 1: \DateInterval}
     */
    public static function schedule(array $sch): array
    {
        SectionFields::only($sch, ['run_at', 'every', 'timezone', 'window', 'catch_up', 'ingestion_delay', 'lock_ttl', 'run_deadline', 'probe_every'], 'schedule');
        $rawProbeEvery = $sch['probe_every'] ?? 'PT1H';
        $probeEvery = is_string($rawProbeEvery) ? SectionFields::seconds($rawProbeEvery) : null; // a non-string is refused like any other bad value, not replaced
        if ($probeEvery === null || $probeEvery < 300) {
            throw new InvalidConfig('schedule.probe_every must be an ISO-8601 duration of at least PT5M');
        }
        $every = $sch['every'] ?? null;
        if ($every !== null && !is_string($every)) {
            throw new InvalidConfig('schedule.every must be one of PT1H, PT2H, PT3H, PT4H, PT6H, PT8H, PT12H');
        }
        if ($every !== null && !in_array($every, \Watcher\Schedule\RunGate::allowedEvery(), true)) {
            throw new InvalidConfig('schedule.every must be one of PT1H, PT2H, PT3H, PT4H, PT6H, PT8H, PT12H');
        }
        $runAtRaw = $sch['run_at'] ?? null;
        $runAtList = [];
        if ($runAtRaw !== null) {
            if (is_string($runAtRaw)) {
                $runAtList = [$runAtRaw];
            } elseif (is_array($runAtRaw) && array_is_list($runAtRaw)) {
                $runAtList = $runAtRaw;
            } else {
                throw new InvalidConfig('schedule.run_at must be HH:MM or a list of HH:MM');
            }
            foreach ($runAtList as $t) {
                if (!is_string($t) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $t) !== 1) {
                    throw new InvalidConfig('schedule.run_at must be HH:MM or a list of HH:MM');
                }
            }
            sort($runAtList);
            if (count($runAtList) !== count(array_unique($runAtList))) {
                throw new InvalidConfig('schedule.run_at entries must be unique');
            }
        }
        if ($every !== null && $runAtList !== []) {
            throw new InvalidConfig('schedule.run_at and schedule.every are mutually exclusive');
        }
        if ($every === null && $runAtList === []) {
            throw new InvalidConfig('schedule.run_at (or schedule.every) is required');
        }
        if ($runAtList !== []) {
            self::assertRunAtGaps($runAtList);
        }
        $tz = SectionFields::optStr($sch, 'timezone', 'UTC', 'schedule.timezone');
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidConfig('schedule.timezone must be an IANA timezone');
        }
        $singleDaily = $every === null && count($runAtList) === 1;
        $windowGiven = array_key_exists('window', $sch);
        $window = null;
        if ($windowGiven) {
            $window = $sch['window'];
            if (!is_string($window)) {
                throw new InvalidConfig('schedule.window must be an ISO-8601 duration');
            }
            try {
                $windowInterval = new \DateInterval($window);
            } catch (\Exception) {
                throw new InvalidConfig('schedule.window must be an ISO-8601 duration');
            }
            if ($windowInterval->y + $windowInterval->m + $windowInterval->d + $windowInterval->h + $windowInterval->i + $windowInterval->s === 0) {
                throw new InvalidConfig('schedule.window must be longer than zero');
            }
        } elseif ($singleDaily) {
            $window = 'PT24H';
            $windowInterval = new \DateInterval($window);
        } else {
            $windowInterval = new \DateInterval('PT1H');
        }
        $catchUp = $sch['catch_up'] ?? 1;
        if (!is_int($catchUp) || $catchUp < 1 || $catchUp > 24) {
            throw new InvalidConfig('schedule.catch_up must be an integer from 1 to 24');
        }
        if ($catchUp > 1 && $every === null) {
            throw new InvalidConfig('schedule.catch_up above 1 needs schedule.every');
        }
        $delay = $sch['ingestion_delay'] ?? 'PT0S';
        try {
            $delaySeconds = (new \DateTimeImmutable('@0'))->add(new \DateInterval($delay))->getTimestamp();
        } catch (\Exception) {
            throw new InvalidConfig('schedule.ingestion_delay must be an ISO-8601 duration');
        }
        if (!is_string($delay) || $delaySeconds < 0) {
            throw new InvalidConfig('schedule.ingestion_delay must be an ISO-8601 duration');
        }
        $periodSeconds = $every !== null
            ? (new \DateTimeImmutable('@0'))->add(new \DateInterval($every))->getTimestamp()
            : self::longestRunAtGapSeconds($runAtList);
        if ($delaySeconds >= $periodSeconds) {
            throw new InvalidConfig('schedule.ingestion_delay must be shorter than the period');
        }
        $runsPerDay = $every !== null ? intdiv(86400, $periodSeconds) : count($runAtList);
        $lockTtl = SectionFields::posInt($sch, 'lock_ttl', 900, 'schedule.lock_ttl');
        $deadline = SectionFields::posInt($sch, 'run_deadline', $lockTtl <= 60 ? max(1, intdiv($lockTtl, 2)) : $lockTtl - max(30, intdiv($lockTtl, 10)), 'schedule.run_deadline');
        if ($deadline > $lockTtl) {
            throw new InvalidConfig('schedule.run_deadline must not be longer than schedule.lock_ttl');
        }
        $heartbeatPeriod = $singleDaily
            ? $windowInterval
            : new \DateInterval('PT' . max(1, $periodSeconds) . 'S');

        return [[
            'run_at' => $runAtList,
            'every' => $every,
            'probe_every' => $probeEvery,
            'timezone' => $tz,
            'window' => $window,
            'catch_up' => $catchUp,
            'ingestion_delay' => $delay,
            'ingestion_delay_seconds' => $delaySeconds,
            'lock_ttl' => $lockTtl,
            'run_deadline' => $deadline,
            'period_seconds' => $periodSeconds,
            'runs_per_day' => $runsPerDay,
            'single_daily_run_at' => $singleDaily,
        ], $heartbeatPeriod];
    }

    /** @param list<string> $runAtList sorted */
    public static function assertRunAtGaps(array $runAtList): void
    {
        $n = count($runAtList);
        for ($i = 0; $i < $n; ++$i) {
            $a = self::minutesOfDay($runAtList[$i]);
            $b = self::minutesOfDay($runAtList[($i + 1) % $n]);
            $gap = $i + 1 < $n ? $b - $a : (1440 - $a) + $b;
            if ($gap < 60) {
                throw new InvalidConfig('schedule.run_at entries must be at least 1 hour apart');
            }
        }
    }

    /** @param list<string> $runAtList */
    public static function longestRunAtGapSeconds(array $runAtList): int
    {
        $max = 0;
        $n = count($runAtList);
        for ($i = 0; $i < $n; ++$i) {
            $a = self::minutesOfDay($runAtList[$i]);
            $b = self::minutesOfDay($runAtList[($i + 1) % $n]);
            $gap = $i + 1 < $n ? $b - $a : (1440 - $a) + $b;
            $max = max($max, $gap);
        }

        return $max * 60;
    }

    public static function minutesOfDay(string $hhmm): int
    {
        return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
    }
}
