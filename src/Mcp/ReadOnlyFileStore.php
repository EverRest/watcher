<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Psr\Clock\ClockInterface;
use Watcher\State\StateStore;

/**
 * Read side of the file state driver for the MCP server (FR-012, research R4). `FileStateStore::get()` creates the directory, takes
 * a lock sidecar and deletes an expired record; none of that is allowed here. Records are replaced by an atomic rename, so a
 * lock-free read sees a whole record. The file name is the driver's (`sha256(key).json`); an expired record is ignored, not deleted.
 */
final class ReadOnlyFileStore implements StateStore
{
    public function __construct(private readonly string $dir, private readonly ClockInterface $clock)
    {
    }

    public function get(string $key): ?array
    {
        $path = $this->dir . '/' . hash('sha256', $key) . '.json';
        $st = @lstat($path);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
            return null; // missing, or a symlink or other non-file: never followed
        }
        $raw = @file_get_contents($path, false, null, 0, 4 * 1024 * 1024);
        if ($raw === false) {
            return null;
        }
        $record = json_decode($raw, true);
        if (!is_array($record) || !is_int($record['expires_at'] ?? null) || !is_array($record['value'] ?? null) || $record['expires_at'] <= $this->clock->now()->getTimestamp()) {
            return null;
        }

        return $record['value'];
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        throw ReadOnlyViolation::of('put');
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        throw ReadOnlyViolation::of('acquire');
    }

    public function release(string $key, string $owner): void
    {
        throw ReadOnlyViolation::of('release');
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        throw ReadOnlyViolation::of('increment');
    }
}
