<?php

declare(strict_types=1);

namespace Watcher\State;

use JsonException;
use Psr\Clock\ClockInterface;
use Redis;
use RuntimeException;

/**
 * Redis state for several overlapping runners (FR-028). Every key carries an expiry,
 * enforced by Redis itself, so the clock is accepted for interface symmetry but unused.
 */
final class RedisStateStore implements StateStore
{
    private const RELEASE = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0";

    // One atomic script (works before Redis 7, where EXPIRE NX is missing): TTL is fixed at the first increment.
    private const INCREMENT = "local v = redis.call('INCRBYFLOAT', KEYS[1], ARGV[1]) "
        . "if redis.call('TTL', KEYS[1]) == -1 then redis.call('EXPIRE', KEYS[1], ARGV[2]) end return v";

    public function __construct(
        private readonly Redis $redis,
        ClockInterface $clock,
        private readonly string $prefix = 'watcher:',
    ) {
        unset($clock); // Redis enforces expiry; parameter kept for the common store signature
    }

    public function get(string $key): ?array
    {
        $raw = $this->redis->get($this->prefix . $key);
        if (! is_string($raw)) {
            return null;
        }
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->redis->set($this->prefix . $key, json_encode($value, JSON_THROW_ON_ERROR), ['EX' => max(1, $ttlSeconds)]);
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->redis->set($this->prefix . $key, json_encode($owner, JSON_THROW_ON_ERROR), ['NX', 'EX' => max(1, $ttlSeconds)]) === true;
    }

    public function release(string $key, string $owner): void
    {
        $this->redis->eval(self::RELEASE, [$this->prefix . $key, json_encode($owner, JSON_THROW_ON_ERROR)], 1);
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        $result = $this->redis->eval(self::INCREMENT, [$this->prefix . $key, (string) $by, (string) max(1, $ttlSeconds)], 1);
        if (! is_string($result) && ! is_int($result) && ! is_float($result)) {
            throw new RuntimeException('Redis increment failed');
        }

        return (float) $result;
    }
}
