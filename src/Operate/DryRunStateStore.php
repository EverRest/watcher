<?php

declare(strict_types=1);

namespace Watcher\Operate;

use Watcher\State\Keys;
use Watcher\State\RenewableLock;
use Watcher\State\StateStore;
use Watcher\State\UnreadableCounter;

/**
 * Dry-run decorator: the real store is only ever read, never written.
 *
 * - `put` lands in an in-memory shadow, so reads later in the same run see it (self-consistent run).
 * - `acquire` succeeds and `release` is a no-op; no lock is left behind.
 * - `increment` is kept in memory only: the real counter is never written, but later reads (and a second dry run in the same
 *   process) see real + dry-run spend, so the cap still bites.
 * - `run:` records of the real store are hidden (the dry run ignores "already done"); the shadow still serves its own.
 * - Not `Purgeable`: nothing can be deleted through it.
 */
final class DryRunStateStore implements StateStore, RenewableLock, UnreadableCounter
{
    /** @var array<string, array<mixed>> */
    private array $shadow = [];

    /** @var array<string, float> counters added in this process; a repeated dry run shows the cumulative spend (A4-06) */
    private array $counted = [];

    public function __construct(private readonly StateStore $real)
    {
    }

    public function unreadableEntries(): int
    {
        return $this->real instanceof UnreadableCounter ? $this->real->unreadableEntries() : 0;
    }

    public function get(string $key): ?array
    {
        if (array_key_exists($key, $this->shadow)) {
            return $this->shadow[$key];
        }
        if (isset($this->counted[$key])) {
            return ['total' => $this->realTotal($key) + $this->counted[$key]];
        }

        return str_starts_with($key, Keys::RUN . ':') ? null : $this->real->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->shadow[$key] = $value;
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        return true;
    }

    public function release(string $key, string $owner): void
    {
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        $this->counted[$key] = ($this->counted[$key] ?? 0.0) + $by;

        return $this->realTotal($key) + $this->counted[$key];
    }

    public function renew(string $key, string $owner, int $ttlSeconds): bool
    {
        return true;
    }

    private function realTotal(string $key): float
    {
        $v = $this->real->get($key)['total'] ?? 0.0;

        return is_numeric($v) ? (float) $v : 0.0;
    }
}
