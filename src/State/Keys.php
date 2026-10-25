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
    public const EXHAUSTED = 'exhausted';
    public const HEARTBEAT = 'hb';
    public const TRIGGER = 'trig';
    public const WEEK = 'hbweek';
    public const WEEKSENT = 'hbsent';
    public const ZERO = 'zero';
    public const DELIVERED = 'sent';
    public const FAILNOTICE = 'failn';
    public const OBSRUNS = 'obsruns';
    public const OBSFAIL = 'obsfail';
    public const OBSDELIV = 'obsdeliv';
    public const OBSSEEN = 'obsseen';
    public const HYGIENE = 'hyg';
    public const LEDGER = 'ledger';
    public const HIGH_WATER = 'hw';
    public const KNOWN = 'known';
    public const DRAFT = 'draft';
    public const REPORTSENT = 'rsent';

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

    /** Failed analysis attempts of one window (a counter under the run prefix, so it expires with the run records). */
    public static function analysisFailures(string $service, string $windowEnd): string
    {
        return self::join(self::RUN, $service, $windowEnd, 'failures');
    }

    /** Marks a window whose read was cut by the deadline or memory guard and retried once (a counter under the run prefix). */
    public static function partial(string $service, string $windowEnd): string
    {
        return self::join(self::RUN, $service, $windowEnd, 'partial');
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

    /** Marks that today's "budget exhausted" notice went out. */
    public static function exhausted(string $service, string $day): string
    {
        return self::join(self::EXHAUSTED, $service, $day);
    }

    public static function heartbeat(string $service): string
    {
        return self::join(self::HEARTBEAT, $service);
    }

    public static function trigger(string $service): string
    {
        return self::join(self::TRIGGER, $service);
    }

    /** Consecutive windows in which the source returned nothing at all (A4-08). */
    public static function zeroRead(string $service): string
    {
        return self::join(self::ZERO, $service);
    }

    /** Marker that a window's digest reached the channel, so a failed state write afterwards cannot cause a second one (A4-07). */
    public static function delivered(string $service, string $windowEnd): string
    {
        return self::join(self::DELIVERED, $service, $windowEnd);
    }

    public static function routeDelivered(string $service, string $windowEnd, int $routeIndex): string
    {
        return self::join(self::DELIVERED, $service, $windowEnd, 'r' . $routeIndex);
    }

    public static function known(string $service, string $fingerprint): string
    {
        return self::join(self::KNOWN, $service, $fingerprint);
    }

    public static function draft(string $service, string $windowEnd, string $fingerprint): string
    {
        return self::join(self::DRAFT, $service, $windowEnd, $fingerprint);
    }

    public static function reportSent(string $service, string $isoWeek): string
    {
        return self::join(self::REPORTSENT, $service, $isoWeek);
    }

    /** Parts of a digest already posted when a later part failed (same window, same TTL as delivered). */
    public static function deliveredParts(string $service, string $windowEnd): string
    {
        return self::join(self::DELIVERED, $service, $windowEnd, 'parts');
    }

    /** Failure notices sent for one window (a counter, at most FAIL_NOTICES_PER_WINDOW are sent). */
    public static function failNotices(string $service, string $windowEnd): string
    {
        return self::join(self::FAILNOTICE, $service, $windowEnd);
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

    /** Observability counters (spec 013): runs per outcome. Expire with state.retention like every other record (default of ttl()). */
    public static function observeRuns(string $service, string $outcome): string
    {
        return self::join(self::OBSRUNS, $service, $outcome);
    }

    public static function observeSinkFailures(string $service, string $sink): string
    {
        return self::join(self::OBSFAIL, $service, $sink);
    }

    public static function observeDeliveryFailures(string $service, string $channel): string
    {
        return self::join(self::OBSDELIV, $service, $channel);
    }

    /** Snapshot of the sink-failure total at the last clean flush of that sink. */
    public static function observeSeen(string $service, string $sink): string
    {
        return self::join(self::OBSSEEN, $service, $sink);
    }

    /** Aggregates of one hygiene pass for a service-local date (spec 006, data-model.md). */
    public static function hygieneDay(string $service, string $date): string
    {
        return self::join(self::HYGIENE, 'day', $service, $date);
    }

    /** Call-site registry: first/last seen, last count and last report time per site. */
    public static function hygieneSites(string $service): string
    {
        return self::join(self::HYGIENE, 'sites', $service);
    }

    /** Declined rule proposals: id => ISO time until which they are not proposed again. */
    public static function hygieneDeclined(string $service): string
    {
        return self::join(self::HYGIENE, 'declined', $service);
    }

    /** One hygiene pass at a time per service: the registry and the day record are read-modify-write. */
    public static function hygieneLock(string $service): string
    {
        return self::join(self::HYGIENE, 'lock', $service);
    }

    /** When each non-privacy report was last sent: name => ISO time. */
    public static function hygieneSent(string $service): string
    {
        return self::join(self::HYGIENE, 'sent', $service);
    }

    /** Model calls and tokens of one service-local date, by step and family (written only when hygiene is enabled). */
    public static function ledger(string $service, string $date): string
    {
        return self::join(self::LEDGER, $service, $date);
    }

    /** Number of billed calls whose ledger write failed on a service-local date (so the cost report can say it is understated). */
    public static function ledgerUnbooked(string $service, string $date): string
    {
        return self::join(self::LEDGER, 'unbooked', $service, $date);
    }

    public static function highWater(string $service): string
    {
        return self::join(self::HIGH_WATER, $service);
    }

    /** @param string $kind one of the kind constants */
    public static function ttl(string $kind, int $retention, int $lockTtl): int
    {
        return match ($kind) {
            self::SPEND, self::ALERT, self::EXHAUSTED => self::SPEND_TTL,
            self::LOCK => $lockTtl,
            self::HEARTBEAT, self::TRIGGER, self::HIGH_WATER => self::HEARTBEAT_TTL_FACTOR * $retention,
            self::WEEK, self::WEEKSENT, self::REPORTSENT => self::WEEK_TTL,
            self::KNOWN => $retention,
            default => $retention,
        };
    }

    private static function join(string $kind, string ...$parts): string
    {
        return $kind . ':' . implode(':', array_map('rawurlencode', $parts));
    }
}
