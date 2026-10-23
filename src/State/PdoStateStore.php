<?php

declare(strict_types=1);

namespace Watcher\State;

use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * SQL state for several overlapping runners (FR-028). The key column is `k` because
 * `key` is reserved in MySQL. Owner is kept inside the stored JSON, `{"o":owner}` for locks.
 */
final class PdoStateStore implements StateStore
{
    private int $writes = 0;

    /** @param int $purgeEvery purge expired rows on every N-th write (0 disables) */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly string $table = 'watcher_state',
        private readonly int $purgeEvery = 50,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException('Invalid table name');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function createTableSql(string $driver = 'sqlite', string $table = 'watcher_state'): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException('Invalid table name');
        }

        return match ($driver) {
            'sqlite' => "CREATE TABLE IF NOT EXISTS {$table} (k VARCHAR(191) PRIMARY KEY, value TEXT NOT NULL, expires_at BIGINT NOT NULL)",
            'mysql' => "CREATE TABLE IF NOT EXISTS {$table} (k VARCHAR(191) NOT NULL PRIMARY KEY, value TEXT NOT NULL, expires_at BIGINT NOT NULL) DEFAULT CHARSET=utf8mb4",
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$table} (k VARCHAR(191) PRIMARY KEY, value TEXT NOT NULL, expires_at BIGINT NOT NULL)",
            default => throw new InvalidArgumentException("Unsupported driver: {$driver}"),
        };
    }

    public function get(string $key): ?array
    {
        $raw = $this->fetchValue($key);
        if ($raw === null) {
            return null;
        }
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        return isset($value['__owner']) ? [] : $value; // a lock reads as [] like the other stores
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        $expires = $this->now() + $ttlSeconds;
        $this->transaction(function () use ($key, $json, $expires): void {
            $update = $this->pdo->prepare("UPDATE {$this->table} SET value = ?, expires_at = ? WHERE k = ?");
            $update->execute([$json, $expires, $key]);
            if ($update->rowCount() === 0) {
                $this->insert($key, $json, $expires);
            }
        });
        $this->maybePurge();
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE k = ? AND expires_at <= ?")->execute([$key, $this->now()]);
        try {
            $this->insert($key, json_encode(['__owner' => $owner], JSON_THROW_ON_ERROR), $this->now() + $ttlSeconds);
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                return false;
            }
            throw $e;
        }
        $this->maybePurge();

        return true;
    }

    public function release(string $key, string $owner): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE k = ? AND value = ?")
            ->execute([$key, json_encode(['__owner' => $owner], JSON_THROW_ON_ERROR)]);
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        $total = $this->transaction(function () use ($key, $by, $ttlSeconds): float {
            $now = $this->now();
            $this->pdo->prepare("DELETE FROM {$this->table} WHERE k = ? AND expires_at <= ?")->execute([$key, $now]);
            // Row is locked by the transaction (write lock / FOR UPDATE) so read-modify-write is atomic.
            $row = $this->pdo->prepare("SELECT value FROM {$this->table} WHERE k = ?" . $this->forUpdate());
            $row->execute([$key]);
            $current = $row->fetchColumn();
            if ($current === false) {
                $total = $by;
                $this->insert($key, json_encode(['total' => $total], JSON_THROW_ON_ERROR), $now + $ttlSeconds);

                return $total;
            }
            $decoded = json_decode((string) $current, true);
            $prev = is_array($decoded) && is_numeric($decoded['total'] ?? null) ? (float) $decoded['total'] : 0.0;
            $total = $prev + $by;
            // expires_at untouched: fixed at the first increment of the window.
            $this->pdo->prepare("UPDATE {$this->table} SET value = ? WHERE k = ?")
                ->execute([json_encode(['total' => $total], JSON_THROW_ON_ERROR), $key]);

            return $total;
        });
        $this->maybePurge();

        return $total;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function fetchValue(string $key): ?string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM {$this->table} WHERE k = ? AND expires_at > ?");
        $stmt->execute([$key, $this->now()]);
        $raw = $stmt->fetchColumn();

        return is_string($raw) ? $raw : null;
    }

    private function insert(string $key, string $json, int $expires): void
    {
        $this->pdo->prepare("INSERT INTO {$this->table} (k, value, expires_at) VALUES (?, ?, ?)")->execute([$key, $json, $expires]);
    }

    private function forUpdate(): string
    {
        return in_array($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';
    }

    private function isDuplicate(PDOException $e): bool
    {
        // SQLSTATE 23xxx = integrity constraint violation (sqlite, mysql, pgsql).
        return str_starts_with((string) $e->getCode(), '23');
    }

    private function maybePurge(): void
    {
        if ($this->purgeEvery > 0 && ++$this->writes % $this->purgeEvery === 0) {
            $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at <= ?")->execute([$this->now()]);
        }
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
