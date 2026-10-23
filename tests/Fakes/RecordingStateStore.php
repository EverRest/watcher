<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use RuntimeException;
use Watcher\State\StateStore;

/** Delegates to a real store and remembers every call; can refuse writes to keys with a given prefix. */
final class RecordingStateStore implements StateStore
{
    /** @var list<array{0: string, 1: string}> method, key */
    public array $calls = [];

    private ?string $failPrefix = null;

    public function __construct(private readonly StateStore $inner)
    {
    }

    public function failWritesTo(string $prefix): void
    {
        $this->failPrefix = $prefix;
    }

    /** @return list<array{0: string, 1: string}> */
    public function writes(): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] !== 'get'));
    }

    /** @return list<string> keys written (put) */
    public function putKeys(): array
    {
        return array_values(array_map(static fn (array $c): string => $c[1], array_filter($this->calls, static fn (array $c): bool => $c[0] === 'put')));
    }

    public function get(string $key): ?array
    {
        $this->calls[] = ['get', $key];

        return $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->calls[] = ['put', $key];
        if ($this->failPrefix !== null && str_starts_with($key, $this->failPrefix)) {
            throw new RuntimeException('put refused');
        }
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->calls[] = ['acquire', $key];

        return $this->inner->acquire($key, $owner, $ttlSeconds);
    }

    public function release(string $key, string $owner): void
    {
        $this->calls[] = ['release', $key];
        $this->inner->release($key, $owner);
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        $this->calls[] = ['increment', $key];
        if ($this->failPrefix !== null && str_starts_with($key, $this->failPrefix)) {
            throw new RuntimeException('increment refused');
        }

        return $this->inner->increment($key, $by, $ttlSeconds);
    }
}
