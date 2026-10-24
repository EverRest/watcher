<?php

declare(strict_types=1);

namespace Watcher\State;

/** Optional: a store whose lock can be extended by its owner while a long run is alive (A4-05). Found with `instanceof`. */
interface RenewableLock
{
    /** Sets the lock's expiry to now + $ttlSeconds when it is still held by $owner; false when it expired or belongs to someone else. */
    public function renew(string $key, string $owner, int $ttlSeconds): bool;
}
