<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Watcher\State\StateStore;

/** FR-010: `get` passes through; every mutator throws. Deliberately not Purgeable and not a RenewableLock. */
final class ReadOnlyStateStore implements StateStore
{
    public function __construct(private readonly StateStore $inner)
    {
    }

    public function get(string $key): ?array
    {
        return $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        throw ReadOnlyViolation::of('put');
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        throw ReadOnlyViolation::of('acquire');
    }

    public function release(string $key, string $owner): void
    {
        throw ReadOnlyViolation::of('release');
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        throw ReadOnlyViolation::of('increment');
    }
}
