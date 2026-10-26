<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use Throwable;
use Watcher\State\RenewableLock;
use Watcher\State\StateStore;

/**
 * Any failure of the state store becomes one exception that keeps the failing class name only: a driver message can carry a DSN, a host or a password,
 * and the report must say `state unavailable (<Class>)`, never "no history" (Principle VII).
 */
final class GuardedState implements StateStore, RenewableLock
{
    public function __construct(private readonly StateStore $inner)
    {
    }

    public function get(string $key): ?array
    {
        return $this->guard(fn (): ?array => $this->inner->get($key));
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->guard(fn () => $this->inner->put($key, $value, $ttlSeconds));
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->guard(fn (): bool => $this->inner->acquire($key, $owner, $ttlSeconds));
    }

    public function release(string $key, string $owner): void
    {
        $this->guard(fn () => $this->inner->release($key, $owner));
    }

    /** True when the wrapped store can extend a lock (develop's RenewableLock); otherwise the lock must outlive the whole pass. */
    public function renewable(): bool
    {
        return $this->inner instanceof RenewableLock;
    }

    public function renew(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->inner instanceof RenewableLock && $this->guard(fn (): bool => $this->inner->renew($key, $owner, $ttlSeconds));
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        return $this->guard(fn (): float => $this->inner->increment($key, $by, $ttlSeconds));
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            // The class only, not chained: the driver message is never kept.
            throw new StateFailure(ReportText::className($e::class));
        }
    }
}
