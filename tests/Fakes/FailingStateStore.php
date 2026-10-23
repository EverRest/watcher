<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use RuntimeException;
use Watcher\State\StateStore;

/** Delegates to a real store until a method is told to fail. */
final class FailingStateStore implements StateStore
{
    /** @var array<string, true> */
    private array $failing = [];

    public function __construct(private readonly StateStore $inner)
    {
    }

    public function failOn(string $method): void
    {
        $this->failing[$method] = true;
    }

    public function get(string $key): ?array
    {
        $this->guard('get');

        return $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->guard('put');
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->guard('acquire');

        return $this->inner->acquire($key, $owner, $ttlSeconds);
    }

    public function release(string $key, string $owner): void
    {
        $this->guard('release');
        $this->inner->release($key, $owner);
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        $this->guard('increment');

        return $this->inner->increment($key, $by, $ttlSeconds);
    }

    private function guard(string $method): void
    {
        if (isset($this->failing[$method])) {
            throw new RuntimeException("state $method failed: connection refused to redis://user:hunter2@cache.internal");
        }
    }
}
