<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use RuntimeException;
use Watcher\State\RenewableLock;
use Watcher\State\StateStore;

/** Delegates to another store, records lock renewals and can fail the writes of one key prefix. */
final class ProbeStateStore implements StateStore, RenewableLock
{
    /** @var list<string> */
    public array $renewed = [];

    public ?string $failPutPrefix = null;

    /** Renew answers false (the lock expired or was taken by another runner). */
    public bool $loseLock = false;

    public function __construct(private readonly StateStore $inner)
    {
    }

    public function get(string $key): ?array
    {
        return $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        if ($this->failPutPrefix !== null && str_starts_with($key, $this->failPutPrefix)) {
            throw new RuntimeException('state down');
        }
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

    public function renew(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->renewed[] = $key;
        if ($this->loseLock) {
            return false;
        }

        return $this->inner instanceof RenewableLock && $this->inner->renew($key, $owner, $ttlSeconds);
    }
}
