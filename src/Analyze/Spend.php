<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use Watcher\State\Keys;
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

    public function day(): string
    {
        return $this->clock->now()->setTimezone($this->timezone)->format('Y-m-d');
    }

    public function add(Usage $usage, float $costUsd): void
    {
        $day = $this->day();
        $ttl = Keys::ttl(Keys::SPEND, 0, 0);
        $this->store->increment(Keys::spendUsd($this->service, $day), $costUsd, $ttl);
        $tokens = $usage->inputTokens + $usage->outputTokens + $usage->cacheReadTokens + $usage->cacheWriteTokens;
        $this->store->increment(Keys::spendTokens($this->service, $day), (float) $tokens, $ttl);
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
