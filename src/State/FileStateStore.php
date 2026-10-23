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
final class FileStateStore implements StateStore
{
    public function __construct(private readonly string $dir, private readonly ClockInterface $clock)
    {
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
        $handle = fopen($this->path($key) . '.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock state key');
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{value: array<mixed>, owner: ?string, expires_at: int}|null */
    private function read(string $key): ?array
    {
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
        file_put_contents($tmp, json_encode($record, JSON_THROW_ON_ERROR));
        rename($tmp, $this->path($key));
    }
}
