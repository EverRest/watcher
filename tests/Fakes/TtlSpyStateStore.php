<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Watcher\State\StateStore;

/** Delegates to a real store and remembers the TTL of the last put per key, so a test can assert the expiry is refreshed. */
final class TtlSpyStateStore implements StateStore
{
    /** @var array<string, int> key => TTL in seconds of the last put */
    public array $ttls = [];

    public function __construct(private readonly StateStore $inner)
    {
    }

    public function get(string $key): ?array
    {
        return $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->ttls[$key] = $ttlSeconds;
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->inner->acquire($key, $owner, $ttlSeconds);
    }

    public function release(string $key, string $owner): void
    {
        $this->inner->release($key, $owner);
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        return $this->inner->increment($key, $by, $ttlSeconds);
    }
}
