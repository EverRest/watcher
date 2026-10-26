<?php

declare(strict_types=1);

namespace Watcher\State;

/**
 * Holds `put` writes in memory until flush(), so state that only makes sense after a successful step
 * (verdicts, reported markers) is never persisted by a run that then fails. Locks and counters pass straight through.
 */
final class BufferedStateStore implements StateStore, RenewableLock
{
    /** @var array<string, array{0: array<mixed>, 1: int}> */
    private array $pending = [];

    public function __construct(private readonly StateStore $inner)
    {
    }

    public function get(string $key): ?array
    {
        return isset($this->pending[$key]) ? $this->pending[$key][0] : $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->pending[$key] = [$value, $ttlSeconds];
    }

    public function flush(): void
    {
        foreach ($this->pending as $key => [$value, $ttl]) {
            $this->inner->put((string) $key, $value, $ttl);
            unset($this->pending[$key]);
        }
    }

    public function discard(): void
    {
        $this->pending = [];
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->inner->acquire($key, $owner, $ttlSeconds);
    }

    public function release(string $key, string $owner): void
    {
        $this->inner->release($key, $owner);
    }

    public function renew(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->inner instanceof RenewableLock && $this->inner->renew($key, $owner, $ttlSeconds);
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        return $this->inner->increment($key, $by, $ttlSeconds);
    }
}
