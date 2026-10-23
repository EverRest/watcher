<?php

declare(strict_types=1);

namespace Watcher\State;

/** Optional: a store whose entries are not expired by the backend itself (the file store). Found with `instanceof`. */
interface Purgeable
{
    /** Removes expired entries and stale helper files. Bounded; returns how many files were removed. */
    public function purge(int $maxFiles = 5000, float $maxSeconds = 2.0): int;
}
