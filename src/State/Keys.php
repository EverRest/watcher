<?php

declare(strict_types=1);

namespace Watcher\State;

/** State key builders (data-model.md). Components are URL-encoded so ':' in a service name cannot collide. */
final class Keys
{
    public const SNAP = 'snap';
    public const DEP = 'dep';
    public const VERDICT = 'verdict';
    public const REPORTED = 'reported';
    public const RUN = 'run';
    public const LOCK = 'lock';
    public const SPEND = 'spend';
    public const ALERT = 'alert';

    private const SPEND_TTL = 172800;

    public static function snapshot(string $service, string $windowEnd): string
    {
        return self::join(self::SNAP, $service, $windowEnd);
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

    /** @param string $kind one of the kind constants */
    public static function ttl(string $kind, int $retention, int $lockTtl): int
    {
        return match ($kind) {
            self::SPEND, self::ALERT => self::SPEND_TTL,
            self::LOCK => $lockTtl,
            default => $retention,
        };
    }

    private static function join(string $kind, string ...$parts): string
    {
        return $kind . ':' . implode(':', array_map('rawurlencode', $parts));
    }
}
