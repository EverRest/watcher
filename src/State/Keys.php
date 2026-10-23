<?php

declare(strict_types=1);

namespace Watcher\State;

/** State key builders (data-model.md). Components are URL-encoded so ':' in a service name cannot collide. */
final class Keys
{
    public const SNAP = 'snap';
    public const DEP = 'dep';
    public const VERDICT = 'verdict';
    public const TRIAGE = 'triage';
    public const REPORTED = 'reported';
    public const RUN = 'run';
    public const LOCK = 'lock';
    public const SPEND = 'spend';
    public const ALERT = 'alert';
    public const HEARTBEAT = 'hb';
    public const TRIGGER = 'trig';
    public const WEEK = 'hbweek';
    public const WEEKSENT = 'hbsent';

    private const SPEND_TTL = 172800;
    private const WEEK_TTL = 1209600;
    private const HEARTBEAT_TTL_FACTOR = 3;

    public static function snapshot(string $service, string $windowEnd): string
    {
        return self::join(self::SNAP, $service, $windowEnd);
    }

    /** Pointer to the newest snapshot written: lets a run after a skipped or failed day find its comparison base. */
    public static function lastSnapshot(string $service): string
    {
        return self::join(self::SNAP, $service, 'last');
    }

    /** Triage verdicts of one window, kept briefly so a retry after a failed analysis does not pay for triage twice. */
    public static function triage(string $service, string $windowEnd): string
    {
        return self::join(self::TRIAGE, $service, $windowEnd);
    }

    public static function dependencySnapshot(string $service, string $name, string $windowEnd): string
    {
        return self::join(self::SNAP, $service, self::DEP, $name, $windowEnd);
    }

    public static function verdict(string $service, string $fingerprint): string
    {
        return self::join(self::VERDICT, $service, $fingerprint);
    }

    public static function reported(string $service, string $fingerprint): string
    {
        return self::join(self::REPORTED, $service, $fingerprint);
    }

    public static function run(string $service, string $windowEnd): string
    {
        return self::join(self::RUN, $service, $windowEnd);
    }

    public static function lock(string $service, string $windowEnd): string
    {
        return self::join(self::LOCK, $service, $windowEnd);
    }

    public static function spend(string $service, string $day): string
    {
        return self::join(self::SPEND, $service, $day);
    }

    public static function spendUsd(string $service, string $day): string
    {
        return self::join(self::SPEND, $service, $day, 'usd');
    }

    public static function spendTokens(string $service, string $day): string
    {
        return self::join(self::SPEND, $service, $day, 'tokens');
    }

    /** @param string $counter 'cost' or 'tokens' */
    public static function alert(string $service, string $day, string $counter, int $percent): string
    {
        return self::join(self::ALERT, $service, $day, $counter, (string) $percent);
    }

    public static function heartbeat(string $service): string
    {
        return self::join(self::HEARTBEAT, $service);
    }

    public static function trigger(string $service): string
    {
        return self::join(self::TRIGGER, $service);
    }

    /** @param string $counter an outcome name or 'cost' */
    public static function week(string $service, string $isoWeek, string $counter): string
    {
        return self::join(self::WEEK, $service, $isoWeek, $counter);
    }

    public static function weekSent(string $service, string $isoWeek): string
    {
        return self::join(self::WEEKSENT, $service, $isoWeek);
    }

    /** @param string $kind one of the kind constants */
    public static function ttl(string $kind, int $retention, int $lockTtl): int
    {
        return match ($kind) {
            self::SPEND, self::ALERT => self::SPEND_TTL,
            self::LOCK => $lockTtl,
            self::HEARTBEAT, self::TRIGGER => self::HEARTBEAT_TTL_FACTOR * $retention,
            self::WEEK, self::WEEKSENT => self::WEEK_TTL,
            default => $retention,
        };
    }

    private static function join(string $kind, string ...$parts): string
    {
        return $kind . ':' . implode(':', array_map('rawurlencode', $parts));
    }
}
