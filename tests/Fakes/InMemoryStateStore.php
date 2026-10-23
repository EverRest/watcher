<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Psr\Clock\ClockInterface;
use Watcher\State\StateStore;

final class InMemoryStateStore implements StateStore
{
    /** @var array<string, array{value: array<mixed>, owner: ?string, expires_at: int}> */
    private array $data = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * Every live value, for privacy scans in tests.
     *
     * @return array<string, array<mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->data) as $key) {
            if (($record = $this->live($key)) !== null) {
                $out[$key] = $record['value'];
            }
        }

        return $out;
    }

    public function get(string $key): ?array
    {
        return $this->live($key)['value'] ?? null;
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->data[$key] = ['value' => $value, 'owner' => null, 'expires_at' => $this->now() + $ttlSeconds];
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        if ($this->live($key) !== null) {
            return false;
        }
        $this->data[$key] = ['value' => [], 'owner' => $owner, 'expires_at' => $this->now() + $ttlSeconds];

        return true;
    }

    public function release(string $key, string $owner): void
    {
        if (($this->live($key)['owner'] ?? null) === $owner) {
            unset($this->data[$key]);
        }
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        $record = $this->live($key);
        $current = $record['value']['total'] ?? 0.0;
        $total = (is_numeric($current) ? (float) $current : 0.0) + $by;
        $this->data[$key] = [
            'value' => ['total' => $total],
            'owner' => null,
            'expires_at' => $record['expires_at'] ?? $this->now() + $ttlSeconds,
        ];

        return $total;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** @return array{value: array<mixed>, owner: ?string, expires_at: int}|null */
    private function live(string $key): ?array
    {
        $record = $this->data[$key] ?? null;
        if ($record !== null && $record['expires_at'] <= $this->now()) {
            unset($this->data[$key]);

            return null;
        }

        return $record;
    }
}
