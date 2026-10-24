<?php

declare(strict_types=1);

namespace Watcher\State;

use JsonException;
use Psr\Clock\ClockInterface;
use Watcher\Config\InvalidConfig;
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
        $this->writeAtomic($marker, (string) $now);
        $deadline = microtime(true) + $maxSeconds;
        $removed = 0;
        $names = @scandir($this->dir) ?: [];
        foreach ($names as $name) {
            if ($removed >= $maxFiles || microtime(true) >= $deadline) {
                break;
            }
            $path = $this->dir . '/' . $name;
            if (! $this->isRegularFile($path)) {
                continue; // only regular files are ever opened or removed (A4-11); lstat, never following a link
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
        try {
            $handle = $this->openSidecar($path . '.lock');
        } catch (RuntimeException) {
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
        try {
            $handle = $this->openSidecar($path);
        } catch (RuntimeException) {
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
            $handle = $this->openSidecar($lockPath);
            if (! flock($handle, LOCK_EX)) {
                fclose($handle);
                throw new RuntimeException('Cannot lock state key');
            }
            // The path may have been unlinked (purge) or swapped while we waited: it must still be the file we hold.
            if ($this->sameFile($handle, $lockPath)) {
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
        $this->writeAtomic($this->path($key), json_encode($record, JSON_THROW_ON_ERROR));
    }

    /**
     * Replaces $target with $contents: written to a random-named temp file created exclusively (never opened by a
     * final path, mode 0600 via umask), then renamed over it. rename() replaces a planted symlink, it never follows it.
     * Fails closed with 'state write failed'.
     */
    private function writeAtomic(string $target, string $contents): void
    {
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $old = umask(0077);
        try {
            $handle = @fopen($tmp, 'x');
        } finally {
            umask($old);
        }
        if ($handle === false) {
            throw new RuntimeException('state write failed');
        }
        $ok = fwrite($handle, $contents) === strlen($contents);
        $ok = fclose($handle) && $ok;
        // A full disk can fail or short-write: never rename a partial file over the good one.
        if (! $ok || ! @rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('state write failed');
        }
    }

    /**
     * Opens a lock sidecar for flock without ever truncating or following a link. PHP's 'x' mode follows a dangling
     * symlink on some platforms, so an absent sidecar is created as a private temp file hard-linked into place
     * (link() fails if anything, a symlink included, is already there). It is then opened ('c' does not truncate)
     * only after lstat shows a regular file we own, and re-checked with fstat against a fresh lstat.
     *
     * @return resource
     */
    private function openSidecar(string $path)
    {
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
            $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $old = umask(0077);
            try {
                $created = @fopen($tmp, 'x');
            } finally {
                umask($old);
            }
            if ($created === false) {
                throw new RuntimeException('Cannot lock state key');
            }
            fclose($created);
            @link($tmp, $path); // losing the race is fine: the winner's file is verified below
            @unlink($tmp);
            clearstatcache(true, $path);
        }
        $st = @lstat($path);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000
            || (function_exists('posix_geteuid') && $st['uid'] !== posix_geteuid())) {
            throw new RuntimeException('state path is a symlink');
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot lock state key');
        }
        if (! $this->sameFile($handle, $path, true)) {
            fclose($handle);
            throw new RuntimeException('state path is a symlink');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function sameFile($handle, string $path, bool $single = false): bool
    {
        clearstatcache(true, $path);
        $held = fstat($handle);
        $now = @lstat($path);

        return $held !== false && $now !== false && ($now['mode'] & 0170000) === 0100000
            && $held['dev'] === $now['dev'] && $held['ino'] === $now['ino'] && $held['nlink'] === $now['nlink']
            && (! $single || $held['nlink'] === 1);
    }

    private function isRegularFile(string $path): bool
    {
        $st = @lstat($path);

        return $st !== false && ($st['mode'] & 0170000) === 0100000;
    }

    /** A state path that is a symlink is never read, locked or written through: someone else planted it. */
    private function refuseLink(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('state path is a symlink');
        }
    }

    /**
     * The directory holds locks and spend counters. Refused: one another user owns (it could forge them) and a
     * world-writable one without the sticky bit. Group-writable and owned by us is allowed (a Laravel `storage` folder);
     * the files themselves are always 0600. The message names the setting, never its value.
     *
     * @throws InvalidConfig
     */
    private function assertDirectory(): void
    {
        clearstatcache(true, $this->dir);
        $perms = @fileperms($this->dir);
        if ($perms === false) {
            return;
        }
        $foreign = function_exists('posix_geteuid') && @fileowner($this->dir) !== posix_geteuid();
        if ($foreign || (($perms & 0002) !== 0 && ($perms & 01000) === 0)) {
            throw new InvalidConfig('state.file.path must be owned by the user running watcher and not world-writable (chmod 0700, or 0770 for a shared group)');
        }
    }
}
