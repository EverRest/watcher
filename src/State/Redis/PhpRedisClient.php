<?php

declare(strict_types=1);

namespace Watcher\State\Redis;

use InvalidArgumentException;
use Redis;

final class PhpRedisClient implements RedisClient
{
    public function __construct(private readonly Redis $redis)
    {
    }

    public function get(string $key): ?string
    {
        $v = $this->redis->get($key);

        return is_string($v) ? $v : null;
    }

    public function set(string $key, string $value, int $ttlMs, bool $onlyIfAbsent = false): bool
    {
        if ($ttlMs < 1) {
            throw new InvalidArgumentException('redis SET without a positive TTL');
        }

        return $this->redis->set($key, $value, $onlyIfAbsent ? ['NX', 'PX' => $ttlMs] : ['PX' => $ttlMs]) === true;
    }

    public function eval(string $script, array $keys, array $args): mixed
    {
        return $this->redis->eval($script, [...$keys, ...$args], count($keys));
    }

    public function pttl(string $key): int
    {
        return (int) $this->redis->pttl($key);
    }
}
