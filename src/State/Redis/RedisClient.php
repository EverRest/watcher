<?php

declare(strict_types=1);

namespace Watcher\State\Redis;

/**
 * The few commands RedisStateStore needs, whatever the client library. INCRBYFLOAT, EXPIRE and DEL only ever
 * run inside the store's Lua scripts, so they are not part of this surface: a key cannot be created here without a TTL.
 */
interface RedisClient
{
    public function get(string $key): ?string;

    /** SET with PX (and NX when $onlyIfAbsent). False when NX refused. $ttlMs must be positive. */
    public function set(string $key, string $value, int $ttlMs, bool $onlyIfAbsent = false): bool;

    /**
     * @param list<string> $keys
     * @param list<string> $args
     */
    public function eval(string $script, array $keys, array $args): mixed;

    /** Milliseconds left; -1 = no expiry, -2 = no such key. */
    public function pttl(string $key): int;
}
