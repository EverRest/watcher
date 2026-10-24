<?php

declare(strict_types=1);

namespace Watcher\State\Redis;

use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * predis/predis (^2.0 || ^3.0) behind the same interface. Takes any object with Predis's command methods, so the
 * package does not depend on Predis: it is a `suggest`. A driver exception is replaced by a fixed message
 * (never chained: it can carry the connection string, password included).
 */
final class PredisClient implements RedisClient
{
    public function __construct(private readonly object $client)
    {
    }

    public function get(string $key): ?string
    {
        $v = $this->call('get', $key);

        return is_string($v) ? $v : null;
    }

    public function set(string $key, string $value, int $ttlMs, bool $onlyIfAbsent = false): bool
    {
        if ($ttlMs < 1) {
            throw new InvalidArgumentException('redis SET without a positive TTL');
        }
        $r = $onlyIfAbsent ? $this->call('set', $key, $value, 'PX', $ttlMs, 'NX') : $this->call('set', $key, $value, 'PX', $ttlMs);

        // Predis answers with a status object ("OK") or null when NX refused.
        return $r === 'OK' || $r === true || ($r instanceof Stringable && (string) $r === 'OK');
    }

    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->call('eval', $script, count($keys), ...$keys, ...$args);
    }

    public function pttl(string $key): int
    {
        $v = $this->call('pttl', $key);

        return is_int($v) ? $v : -2;
    }

    private function call(string $command, mixed ...$args): mixed
    {
        try {
            return $this->client->$command(...$args);
        } catch (Throwable) {
            throw new RuntimeException('state.redis: predis command failed');
        }
    }
}
