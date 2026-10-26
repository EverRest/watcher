<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Closure;
use Watcher\State\StateStore;

/** Delegates to a real store; every call goes through $hook(method, key, ttl) first, which may throw. */
final class HookedStateStore implements StateStore
{
    /** @param Closure(string, string, int): void $hook */
    public function __construct(private readonly StateStore $inner, private readonly Closure $hook)
    {
    }

    public function get(string $key): ?array
    {
        ($this->hook)('get', $key, 0);

        return $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        ($this->hook)('put', $key, $ttlSeconds);
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        ($this->hook)('acquire', $key, $ttlSeconds);

        return $this->inner->acquire($key, $owner, $ttlSeconds);
    }

    public function release(string $key, string $owner): void
    {
        $this->inner->release($key, $owner);
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        ($this->hook)('increment', $key, $ttlSeconds);

        return $this->inner->increment($key, $by, $ttlSeconds);
    }
}
