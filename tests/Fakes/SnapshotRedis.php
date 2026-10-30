<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use LogicException;
use RuntimeException;
use Watcher\State\Redis\RedisClient;

/** The host app's Redis as the health check sees it: one readable value, read only. Can refuse the read, to test the failure path. */
final class SnapshotRedis implements RedisClient
{
    public ?string $value = null;

    public bool $fail = false;

    public function get(string $key): ?string
    {
        if ($this->fail) {
            throw new RuntimeException('connection refused');
        }

        return $this->value;
    }

    public function set(string $key, string $value, int $ttlMs, bool $onlyIfAbsent = false): bool
    {
        throw new LogicException('the host source is read only');
    }

    /** A method of the RedisClient interface (Redis EVAL), never run here: it only refuses, the health check never writes. */
    public function eval(string $script, array $keys, array $args): mixed
    {
        throw new LogicException('the host source is read only');
    }

    public function pttl(string $key): int
    {
        return -2;
    }
}
