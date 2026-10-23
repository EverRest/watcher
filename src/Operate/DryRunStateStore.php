<?php

declare(strict_types=1);

namespace Watcher\Operate;

use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * Dry-run decorator: the real store is only ever read, never written.
 *
 * - `put` lands in an in-memory shadow, so reads later in the same run see it (self-consistent run).
 * - `acquire` succeeds and `release` is a no-op; no lock is left behind.
 * - `increment` is swallowed and returns `$by`, so spend is not recorded and budget alerts see no change.
 * - `run:` records of the real store are hidden (the dry run ignores "already done"); the shadow still serves its own.
 * - Not `Purgeable`: nothing can be deleted through it.
 */
final class DryRunStateStore implements StateStore
{
    /** @var array<string, array<mixed>> */
    private array $shadow = [];

    public function __construct(private readonly StateStore $real)
    {
    }

    public function get(string $key): ?array
    {
        if (array_key_exists($key, $this->shadow)) {
            return $this->shadow[$key];
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
        return $by;
    }
}
