<?php

declare(strict_types=1);

namespace Watcher\State;

use JsonException;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * JSON-file state: one file per key, `flock` for mutual exclusion, write-to-temp-and-rename.
 *
 * Valid for a single runner only (FR-028): flock does not coordinate across hosts or
 * network filesystems. Use the Redis or PDO store when more than one runner can overlap.
 *
 * Keys are hashed into the filename, so a key can never address a path outside $dir.
 */
final class FileStateStore implements StateStore, Purgeable, RenewableLock
{
    private const PURGE_INTERVAL = 86400;

    /** @param int $lockLifetime seconds after which a lock sidecar or temp file is considered abandoned (schedule.lock_ttl) */
    public function __construct(
        private readonly string $dir,
        private readonly ClockInterface $clock,
        private readonly int $lockLifetime = 900,
    ) {
    }

    public function purge(int $maxFiles = 5000, float $maxSeconds = 2.0): int
    {
        if (! is_dir($this->dir)) {
            return 0;
        }
        $now = $this->now();
        $this->assertDirectory();
        $marker = $this->dir . '/.purge';
        $this->refuseLink($marker);
        $last = (int) @file_get_contents($marker);
        if ($last > 0 && $now - $last < self::PURGE_INTERVAL) {
            return 0;
        }
        // ponytail: marker is written before the sweep, so a backlog above maxFiles drains one batch per day.
        $this->writeNew($marker, (string) $now, true);
        $deadline = microtime(true) + $maxSeconds;
        $removed = 0;
        $names = @scandir($this->dir) ?: [];
        foreach ($names as $name) {
            if ($removed >= $maxFiles || microtime(true) >= $deadline) {
                break;
            }
            $path = $this->dir . '/' . $name;
            if (is_link($path) || (is_file($path) === false)) {
                continue; // only regular files are ever opened or removed (A4-11)
            }
            if (preg_match('/^[0-9a-f]{64}\.json$/D', $name) === 1) {
                $removed += $this->purgeRecord($path, $now);
            } elseif (preg_match('/^[0-9a-f]{64}\.json\.lock$/D', $name) === 1) {
                $removed += $this->purgeSidecar($path, $now);
            } elseif (preg_match('/^[0-9a-f]{64}\.json\.[0-9a-f]{8}\.tmp$/D', $name) === 1 && $this->abandoned($path, $now)) {
                $removed += @unlink($path) ? 1 : 0;
            }
        }

        return $removed;
    }

    private function abandoned(string $path, int $now): bool
    {
        clearstatcache(true, $path);
        $mtime = @filemtime($path);

        return $mtime !== false && $now - $mtime > $this->lockLifetime;
    }

    /** Deletes an expired record under its key lock, so a concurrent put is never undone. A held lock means skip. */
    private function purgeRecord(string $path, int $now): int
    {
        if (is_link($path . '.lock')) {
            return 0;
        }
        $handle = @fopen($path . '.lock', 'c');
        if ($handle === false) {
            return 0;
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return 0;
            }
            $record = json_decode((string) @file_get_contents($path), true);
            if (is_array($record) && is_int($record['expires_at'] ?? null) && $record['expires_at'] <= $now) {
                return @unlink($path) ? 1 : 0;
            }

            return 0;
        } finally {
            fclose($handle);
        }
    }

    /** Removes an old, unheld sidecar. Waiters on the old inode notice in locked() and reopen. */
    private function purgeSidecar(string $path, int $now): int
    {
        if (! $this->abandoned($path, $now)) {
            return 0;
        }
        $handle = @fopen($path, 'c');
        if ($handle === false || is_link($path)) {
            return 0;
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return 0;
            }

            return @unlink($path) ? 1 : 0;
        } finally {
            fclose($handle);
        }
    }

    public function get(string $key): ?array
    {
        return $this->locked($key, fn (): ?array => $this->read($key)['value'] ?? null);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->locked($key, function () use ($key, $value, $ttlSeconds): void {
            $this->write($key, ['value' => $value, 'owner' => null, 'expires_at' => $this->now() + $ttlSeconds]);
        });
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->locked($key, function () use ($key, $owner, $ttlSeconds): bool {
            if ($this->read($key) !== null) {
                return false;
            }
            $this->write($key, ['value' => [], 'owner' => $owner, 'expires_at' => $this->now() + $ttlSeconds]);

            return true;
        });
    }

    public function release(string $key, string $owner): void
    {
        $this->locked($key, function () use ($key, $owner): void {
            if (($this->read($key)['owner'] ?? null) === $owner) {
                @unlink($this->path($key));
            }
        });
    }

    public function renew(string $key, string $owner, int $ttlSeconds): bool
    {
        return $this->locked($key, function () use ($key, $owner, $ttlSeconds): bool {
            $record = $this->read($key);
            if ($record === null || $record['owner'] !== $owner) {
                return false;
            }
            $record['expires_at'] = $this->now() + $ttlSeconds;
            $this->write($key, $record);

            return true;
        });
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        return $this->locked($key, function () use ($key, $by, $ttlSeconds): float {
            $record = $this->read($key);
            $current = $record['value']['total'] ?? 0.0;
            $total = (is_numeric($current) ? (float) $current : 0.0) + $by;
            // Expiry is fixed at first increment of a window, not extended by later ones.
            $expires = $record['expires_at'] ?? $this->now() + $ttlSeconds;
            $this->write($key, ['value' => ['total' => $total], 'owner' => null, 'expires_at' => $expires]);

            return $total;
        });
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function locked(string $key, callable $fn): mixed
    {
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0700, true) && ! is_dir($this->dir)) {
            throw new RuntimeException('Cannot create state directory');
        }
        $this->assertDirectory();
        $handle = $this->lock($this->path($key) . '.lock');
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Takes the sidecar lock. A purge may unlink the sidecar while we wait on it; two holders could then lock two
     * different inodes of the same path, so after flock the path must still be the file we hold, else reopen.
     *
     * @return resource
     */
    private function lock(string $lockPath): mixed
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->refuseLink($lockPath);
            $handle = fopen($lockPath, 'c');
            if ($handle === false || ! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock state key');
            }
            @chmod($lockPath, 0600);
            clearstatcache(true, $lockPath);
            $held = fstat($handle);
            $path = @stat($lockPath);
            if ($held !== false && $path !== false && $held['ino'] === $path['ino'] && $held['dev'] === $path['dev']) {
                return $handle;
            }
            fclose($handle);
        }
        throw new RuntimeException('Cannot lock state key');
    }

    /** @return array{value: array<mixed>, owner: ?string, expires_at: int}|null */
    private function read(string $key): ?array
    {
        $this->refuseLink($this->path($key));
        $raw = @file_get_contents($this->path($key));
        if ($raw === false) {
            return null;
        }
        try {
            $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($record) || ! is_int($record['expires_at'] ?? null) || ! is_array($record['value'] ?? null)) {
            return null;
        }
        if ($record['expires_at'] <= $this->now()) {
            @unlink($this->path($key));

            return null;
        }
        $owner = $record['owner'] ?? null;

        return ['value' => $record['value'], 'owner' => is_string($owner) ? $owner : null, 'expires_at' => $record['expires_at']];
    }

    /** @param array{value: array<mixed>, owner: ?string, expires_at: int} $record */
    private function write(string $key, array $record): void
    {
        $tmp = $this->path($key) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        // A full disk can fail or short-write: never rename a partial file over the good record.
        $this->writeNew($tmp, $json);
        if (! @rename($tmp, $this->path($key))) {
            @unlink($tmp);
            throw new RuntimeException('state write failed');
        }
    }

    /**
     * Creates $path (never following a symlink, mode 0600) and writes $contents. $replace: the file may already exist
     * (the purge marker); it is then rewritten in place after the symlink check. Fails closed with 'state write failed'.
     */
    private function writeNew(string $path, string $contents, bool $replace = false): void
    {
        $this->refuseLink($path);
        $handle = @fopen($path, $replace ? 'c' : 'x');
        if ($handle === false) {
            throw new RuntimeException('state write failed');
        }
        try {
            @chmod($path, 0600);
            if ($replace) {
                ftruncate($handle, 0);
            }
            if (fwrite($handle, $contents) !== strlen($contents)) {
                @unlink($path);
                throw new RuntimeException('state write failed');
            }
        } finally {
            fclose($handle);
        }
    }

    /** A state path that is a symlink is never read, locked or written through: someone else planted it. */
    private function refuseLink(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('state path is a symlink');
        }
    }

    /** The directory holds locks and spend counters: group or world write access lets another user forge them. */
    private function assertDirectory(): void
    {
        clearstatcache(true, $this->dir);
        $perms = @fileperms($this->dir);
        if ($perms === false) {
            return;
        }
        if (($perms & 0022) !== 0) {
            throw new RuntimeException('state.file.path must not be writable by group or others (chmod 0700)');
        }
    }
}
