<?php

declare(strict_types=1);

namespace Watcher\Schedule;

use Closure;
use Psr\Clock\ClockInterface;

/**
 * The run's wall-clock deadline and memory guard (A4-01, A4-02). `reason()` is asked between pipeline steps and from
 * the read loops: `deadline` once the time budget is spent, `memory` once usage reaches 80% of memory_limit. Sticky: a reason
 * that was given stays, so every later step sees the same truth. Never throws, never allocates beyond two ints.
 */
final class RunGuard
{
    private const MEMORY_SHARE = 0.8;

    private readonly int $startedAt;
    private ?string $reason = null;

    /** @var Closure(): array{0: int, 1: int} used bytes, limit bytes (-1 = none) */
    private readonly Closure $memory;

    /**
     * @param int|null $deadlineSeconds seconds from construction; null = no deadline
     * @param (callable(): array{0: int, 1: int})|null $memory defaults to memory_get_usage() against ini memory_limit
     */
    public function __construct(private readonly ClockInterface $clock, private readonly ?int $deadlineSeconds, ?callable $memory = null)
    {
        $this->startedAt = $clock->now()->getTimestamp();
        $this->memory = $memory === null
            ? static fn (): array => [memory_get_usage(true), self::bytes((string) ini_get('memory_limit'))]
            : Closure::fromCallable($memory);
    }

    public function reason(): ?string
    {
        if ($this->reason !== null) {
            return $this->reason;
        }
        if ($this->deadlineSeconds !== null && $this->clock->now()->getTimestamp() - $this->startedAt >= $this->deadlineSeconds) {
            return $this->reason = 'deadline';
        }
        [$used, $limit] = ($this->memory)();
        if ($limit > 0 && $used >= (int) floor($limit * self::MEMORY_SHARE)) {
            return $this->reason = 'memory';
        }

        return null;
    }

    public function remainingSeconds(): ?int
    {
        return $this->deadlineSeconds === null ? null : max(0, $this->deadlineSeconds - ($this->clock->now()->getTimestamp() - $this->startedAt));
    }

    /** php.ini shorthand ("128M", "1G", "-1") to bytes; unparsable = -1 (no guard). */
    public static function bytes(string $ini): int
    {
        $ini = trim($ini);
        if (preg_match('/^(-?\d+)\s*([kmg]?)$/i', $ini, $m) !== 1) {
            return -1;
        }

        return (int) $m[1] * match (strtolower($m[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };
    }
}
