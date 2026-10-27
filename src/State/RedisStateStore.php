<?php

declare(strict_types=1);

namespace Watcher\State;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Redis;
use RuntimeException;
use UnexpectedValueException;
use Watcher\State\Redis\PhpRedisClient;
use Watcher\State\Redis\RedisClient;

/**
 * Redis state for several overlapping runners (FR-028). Every key carries an expiry, enforced by Redis itself, so the
 * clock is accepted for interface symmetry but unused. That rule is built in, not left to callers: the single SET
 * (write()) takes a positive TTL, the counter script sets PEXPIRE in the same script as INCRBYFLOAT, and a TTL
 * of zero or less is refused (the key kind is named in the message, never the key).
 */
final class RedisStateStore implements StateStore, RenewableLock, UnreadableCounter
{
    public const RELEASE = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0";

    public const RENEW = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('PEXPIRE', KEYS[1], ARGV[2]) end return 0";

    // One atomic script (works before Redis 7, where EXPIRE NX is missing): INCRBYFLOAT never leaves the key without
    // an expiry; the TTL is fixed at the first increment.
    public const INCREMENT = "local v = redis.call('INCRBYFLOAT', KEYS[1], ARGV[1]) "
        . "if redis.call('PTTL', KEYS[1]) < 0 then redis.call('PEXPIRE', KEYS[1], ARGV[2]) end return v";


    /** Entries that were present but could not be decoded (corrupt, over the size cap, or compressed without ext-zlib), read as missing. */
    public int $unreadable = 0;

    private readonly RedisClient $client;

    public function __construct(
        Redis|RedisClient $redis,
        ClockInterface $clock,
        private readonly string $prefix = 'watcher:',
        private readonly int $compressMinBytes = 512,
        private readonly ?LoggerInterface $log = null,
    ) {
        unset($clock); // Redis enforces expiry; parameter kept for the common store signature
        $this->client = $redis instanceof Redis ? new PhpRedisClient($redis) : $redis;
    }

    public function unreadableEntries(): int
    {
        return $this->unreadable;
    }

    public function get(string $key): ?array
    {
        $raw = $this->client->get($this->prefix . $key);
        if ($raw === null) {
            return null;
        }
        // increment() stores a bare number (INCRBYFLOAT); return it in the shape the other stores use.
        if (is_numeric($raw)) {
            return ['total' => (float) $raw];
        }
        try {
            return StateCodec::decode($raw, function_exists('gzuncompress'));
        } catch (UnexpectedValueException) {
            // Missing, not an error: the next run rebuilds it. Only the key kind is logged (the key carries the service name).
            $this->unreadable++;
            $this->log?->warning('redis state entry unreadable, treated as missing', ['kind' => self::kind($key)]);

            return null;
        }
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->write($key, StateCodec::encode($value, $this->compressMinBytes, function_exists('gzcompress')), $ttlSeconds, false);
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->write($key, json_encode($owner, JSON_THROW_ON_ERROR), $ttlSeconds, true);
    }

    public function release(string $key, string $owner): void
    {
        $this->client->eval(self::RELEASE, [$this->prefix . $key], [json_encode($owner, JSON_THROW_ON_ERROR)]);
    }

    public function renew(string $key, string $owner, int $ttlSeconds): bool
    {
        $ms = $this->ttlMs($key, $ttlSeconds);

        return $this->client->eval(self::RENEW, [$this->prefix . $key], [json_encode($owner, JSON_THROW_ON_ERROR), (string) $ms]) === 1;
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        $ms = $this->ttlMs($key, $ttlSeconds);
        $result = $this->client->eval(self::INCREMENT, [$this->prefix . $key], [(string) $by, (string) $ms]);
        if (! is_string($result) && ! is_int($result) && ! is_float($result)) {
            throw new RuntimeException('Redis increment failed');
        }

        return (float) $result;
    }

    /** The only place a value is stored: it cannot be reached without a positive TTL. */
    private function write(string $key, string $payload, int $ttlSeconds, bool $onlyIfAbsent): bool
    {
        return $this->client->set($this->prefix . $key, $payload, $this->ttlMs($key, $ttlSeconds), $onlyIfAbsent);
    }

    private function ttlMs(string $key, int $ttlSeconds): int
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException("redis state: ttl must be a positive number of seconds (key kind '" . self::kind($key) . "')");
        }

        return $ttlSeconds * 1000;
    }

    /** The leading `kind` of a logical key (`run`, `lock`, ...): safe to log, unlike the key, which carries a service name. */
    private static function kind(string $key): string
    {
        $head = explode(':', $key, 2)[0];

        return preg_match('/^[a-z][a-z0-9_-]{0,15}$/', $head) === 1 ? $head : 'unknown';
    }
}
