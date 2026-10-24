<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use Watcher\State\Keys;
use Watcher\State\ReservableCounters;
use Watcher\State\StateStore;

/** Per-service, per-calendar-day atomic counters: USD and tokens (FR-022, FR-046). */
final class Spend
{
    public function __construct(
        private readonly StateStore $store,
        private readonly ClockInterface $clock,
        private readonly DateTimeZone $timezone,
        private readonly string $service,
    ) {
    }

    /** What this run reserved for the call in progress and has not settled yet (M2). */
    private float $pendingUsd = 0.0;
    private float $pendingTokens = 0.0;
    /** The day the reservation was made on: it is settled or released on that day's counters, whatever the clock says by then. */
    private string $pendingDay = '';

    public function day(): string
    {
        return $this->clock->now()->setTimezone($this->timezone)->format('Y-m-d');
    }

    /** Books what a call cost. A reservation made for it is settled in the same step: only the difference is added. */
    public function add(Usage $usage, float $costUsd): void
    {
        $day = $this->day();
        $ttl = Keys::ttl(Keys::SPEND, 0, 0);
        $tokens = $usage->inputTokens + $usage->outputTokens + $usage->cacheReadTokens + $usage->cacheWriteTokens;
        if ($this->pendingDay !== '' && $this->pendingDay !== $day) {
            $this->release(); // the reservation belongs to an earlier day: give it back there, book the real cost today
        }
        $this->store->increment(Keys::spendUsd($this->service, $day), $costUsd - $this->pendingUsd, $ttl);
        $this->store->increment(Keys::spendTokens($this->service, $day), (float) $tokens - $this->pendingTokens, $ttl);
        $this->pendingUsd = $this->pendingTokens = 0.0;
    }

    /**
     * Whether a step of this worst case fits under the caps. On a store that can reserve (Redis) the check and the
     * reservation are one atomic step, so parallel runs cannot all pass it; the reservation is settled by add() or given
     * back by release(). Other stores keep the plain check against what is booked so far.
     */
    public function reserve(float $usd, int $tokens, float $capUsd, ?int $capTokens): bool
    {
        $this->release(); // a reservation is for one call at a time
        if ($this->store instanceof ReservableCounters) {
            $day = $this->day();
            $ok = $this->store->reserve(Keys::spendUsd($this->service, $day), Keys::spendTokens($this->service, $day), $usd, (float) $tokens, $capUsd + 1e-9, $capTokens === null ? null : (float) $capTokens, Keys::ttl(Keys::SPEND, 0, 0));
            if ($ok !== null) {
                if ($ok) {
                    [$this->pendingUsd, $this->pendingTokens, $this->pendingDay] = [$usd, (float) $tokens, $day];
                }

                return $ok;
            }
        }
        $today = $this->today();

        return ($capTokens === null || $today['tokens'] + $tokens <= $capTokens) && $today['usd'] + $usd <= $capUsd + 1e-9;
    }

    /** Gives back a reservation whose call failed before anything was billed (or never happened). */
    public function release(): void
    {
        if ($this->pendingUsd === 0.0 && $this->pendingTokens === 0.0) {
            return;
        }
        $day = $this->pendingDay;
        $ttl = Keys::ttl(Keys::SPEND, 0, 0);
        [$usd, $tokens] = [$this->pendingUsd, $this->pendingTokens];
        $this->pendingUsd = $this->pendingTokens = 0.0;
        $this->store->increment(Keys::spendUsd($this->service, $day), -$usd, $ttl);
        $this->store->increment(Keys::spendTokens($this->service, $day), -$tokens, $ttl);
    }

    /** @return array{usd: float, tokens: int} */
    public function today(): array
    {
        $day = $this->day();

        return [
            'usd' => self::total($this->store->get(Keys::spendUsd($this->service, $day))),
            'tokens' => (int) round(self::total($this->store->get(Keys::spendTokens($this->service, $day)))),
        ];
    }

    public function exhausted(float $dailyBudgetUsd, ?int $dailyTokenLimit): bool
    {
        $t = $this->today();

        return $t['usd'] >= $dailyBudgetUsd || ($dailyTokenLimit !== null && $t['tokens'] >= $dailyTokenLimit);
    }

    /** @param array<mixed>|null $record */
    private static function total(?array $record): float
    {
        $v = $record['total'] ?? 0.0;

        return is_numeric($v) ? (float) $v : 0.0;
    }
}
