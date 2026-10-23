<?php

declare(strict_types=1);

namespace Watcher\State;

interface StateStore
{
    /** @return array<mixed>|null */
    public function get(string $key): ?array;

    /** @param array<mixed> $value */
    public function put(string $key, array $value, int $ttlSeconds): void;

    /** Atomic add-if-absent with expiry (FR-028). Returns false if the key exists. */
    public function acquire(string $key, string $owner, int $ttlSeconds): bool;

    public function release(string $key, string $owner): void;

    /** Atomic float increment for the daily spend counter (FR-022). */
    public function increment(string $key, float $by, int $ttlSeconds): float;
}
