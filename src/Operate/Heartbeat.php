<?php

declare(strict_types=1);

namespace Watcher\Operate;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Watcher\Notify\Destination;
use Watcher\Notify\NoticeRenderer;
use Watcher\Notify\Notifier;
use Watcher\RunOutcome;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * Liveness record, trigger record and weekly counters (spec 002, US1). No write here ever throws: a store or
 * notifier fault is logged (class name only, never the message) and the run's outcome is untouched (FR-005).
 *
 * Integration from Watcher::run():
 *  - after a run that finished as Reported|Quiet|Baselined|Failed:
 *      $hb->recordRun($outcome, $windowStart, $windowEnd, $startedAt, $costUsd, $failureReason);
 *  - on SkippedDone|SkippedLocked: $hb->recordSkip($outcome);  (SkippedNotDue and InvalidConfig: call nothing,
 *    or call anyway: both are ignored and touch no state)
 *  - if heartbeat.weekly_summary and the run is due: $hb->sendWeeklySummary($notifier, $noticeDestination, $notices, $env);
 *  - `watcher status`: $hb->status($config->heartbeat['stale_after']) -> HeartbeatStatus (ok() false => exit non-zero).
 */
final class Heartbeat
{
    private const MAX_REASON = 200;
    private const MIN_SECRET = 8;
    private const OK_OUTCOMES = ['reported', 'quiet', 'baselined'];
    private const SKIPPED = ['skipped_done', 'skipped_locked'];

    /** @param list<string> $secrets exact values (API keys, webhooks) masked in the stored failure reason */
    public function __construct(
        private readonly StateStore $store,
        private readonly ClockInterface $clock,
        private readonly string $service,
        private readonly DateTimeZone $timezone,
        private readonly int $retention,
        private readonly ?LoggerInterface $log = null,
        private readonly array $secrets = [],
    ) {
    }

    public function recordRun(
        RunOutcome $outcome,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        DateTimeImmutable $startedAt,
        float $costUsd = 0.0,
        ?string $reason = null,
    ): void {
        if (!in_array($outcome->value, [...self::OK_OUTCOMES, 'failed'], true)) {
            return;
        }
        $now = $this->clock->now();
        $this->guard('heartbeat write', function () use ($outcome, $windowStart, $windowEnd, $startedAt, $costUsd, $reason, $now): void {
            $ok = $outcome !== RunOutcome::Failed;
            $previous = $this->store->get(Keys::heartbeat($this->service));
            $lastOk = $ok ? $now->format(DATE_ATOM) : ($previous['lastOkAt'] ?? null);
            $this->store->put(Keys::heartbeat($this->service), [
                'v' => 1,
                'service' => $this->service,
                'finishedAt' => $now->format(DATE_ATOM),
                'outcome' => $outcome->value,
                'reason' => $reason === null ? null : $this->reason($reason),
                'windowStart' => $windowStart->format(DATE_ATOM),
                'windowEnd' => $windowEnd->format(DATE_ATOM),
                'durationSeconds' => max(0, $now->getTimestamp() - $startedAt->getTimestamp()),
                'costUsd' => $costUsd,
                'lastOkAt' => is_string($lastOk) ? $lastOk : null,
            ], Keys::ttl(Keys::HEARTBEAT, $this->retention, 0));
        });
        $this->guard('weekly counter', function () use ($outcome, $costUsd): void {
            $this->count($outcome->value, 1.0);
            if ($costUsd > 0) {
                $this->count('cost', $costUsd);
            }
        });
    }

    /** Only a due trigger that did not run (done, locked) writes anything; a not-due trigger touches no state. */
    public function recordSkip(RunOutcome $outcome): void
    {
        if (!in_array($outcome->value, self::SKIPPED, true)) {
            return;
        }
        $this->guard('trigger write', fn () => $this->store->put(Keys::trigger($this->service), ['at' => $this->clock->now()->format(DATE_ATOM)], Keys::ttl(Keys::TRIGGER, $this->retention, 0)));
        $this->guard('weekly counter', fn () => $this->count($outcome->value, 1.0));
    }

    /** Never throws: an unreadable store is "unknown". Healthy only when the last ok run is at most $limitSeconds old. */
    public function status(int $limitSeconds): HeartbeatStatus
    {
        $now = $this->clock->now();
        try {
            $record = $this->store->get(Keys::heartbeat($this->service));
            $trigger = $this->store->get(Keys::trigger($this->service));
        } catch (Throwable $e) {
            $this->log?->warning('heartbeat not readable: ' . $e::class, ['service' => $this->service]);
            $record = $trigger = null;
        }
        $lastOk = self::time($record['lastOkAt'] ?? null);
        $age = $lastOk === null ? null : $now->getTimestamp() - $lastOk->getTimestamp();
        $state = match (true) {
            $record === null => 'unknown',
            ($record['outcome'] ?? null) === 'failed' => 'failed',
            $age === null => 'unknown',
            $age > $limitSeconds => 'stale',
            default => 'ok',
        };

        return new HeartbeatStatus($state, $record, $age, $limitSeconds, $now, $lastOk, self::time($trigger['at'] ?? null));
    }

    /**
     * Sends last ISO week's counts once (first call of the new week wins); false when nothing was sent. A week
     * with no reported/quiet/baselined/failed run sends nothing (the heartbeat alert covers that case).
     */
    public function sendWeeklySummary(Notifier $notifier, Destination $to, NoticeRenderer $notices, string $environment): bool
    {
        $week = $this->now()->modify('-7 days')->format('o-\WW');
        $owner = null;
        try {
            $counts = [];
            foreach ([...self::OK_OUTCOMES, 'failed', ...self::SKIPPED, 'cost'] as $name) {
                $counts[$name] = $this->total($week, $name);
            }
            if ($counts['reported'] + $counts['quiet'] + $counts['baselined'] + $counts['failed'] <= 0) {
                return false;
            }
            $owner = bin2hex(random_bytes(8));
            if (!$this->store->acquire(Keys::weekSent($this->service, $week), $owner, Keys::ttl(Keys::WEEKSENT, $this->retention, 0))) {
                return false;
            }
            $notifier->send($notices->weekly($this->service, $environment, $week, [
                'reported' => (int) $counts['reported'],
                'quiet' => (int) $counts['quiet'],
                'baselined' => (int) $counts['baselined'],
                'failed' => (int) $counts['failed'],
                'skipped' => (int) ($counts['skipped_done'] + $counts['skipped_locked']),
            ], $counts['cost']), $to);

            return true;
        } catch (Throwable $e) {
            $this->log?->warning('weekly summary not delivered: ' . $e::class, ['service' => $this->service]);
            if ($owner !== null) {
                $this->guard('weekly marker release', fn () => $this->store->release(Keys::weekSent($this->service, $week), (string) $owner));
            }

            return false;
        }
    }

    private function count(string $counter, float $by): void
    {
        $this->store->increment(Keys::week($this->service, $this->now()->format('o-\WW'), $counter), $by, Keys::ttl(Keys::WEEK, $this->retention, 0));
    }

    private function total(string $week, string $counter): float
    {
        $v = $this->store->get(Keys::week($this->service, $week, $counter))['total'] ?? 0.0;

        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->timezone);
    }

    private function guard(string $what, callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            $this->log?->warning($what . ' failed: ' . $e::class, ['service' => $this->service]);
        }
    }

    private static function time(mixed $v): ?DateTimeImmutable
    {
        try {
            return is_string($v) ? new DateTimeImmutable($v) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Same rules as RunResult::summary() / NoticeRenderer: one short line, no quotes, no URLs, no secrets. */
    private function reason(string $reason): string
    {
        foreach ($this->secrets as $secret) {
            if (strlen($secret) >= self::MIN_SECRET) {
                $reason = str_replace($secret, '[redacted]', $reason);
            }
        }
        $reason = (string) preg_replace('~\b[a-z][a-z0-9+.-]*://\S+~i', '[url]', $reason);
        $reason = (string) preg_replace('/[\w-]{24,}/', '[redacted]', $reason);
        $reason = trim((string) preg_replace('/\s+/u', ' ', str_replace('"', "'", $reason)));

        return mb_strlen($reason) <= self::MAX_REASON ? $reason : mb_substr($reason, 0, self::MAX_REASON - 1) . '…';
    }
}
