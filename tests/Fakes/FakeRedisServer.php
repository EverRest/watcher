<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Psr\Clock\ClockInterface;
use Watcher\State\Redis\RedisClient;
use Watcher\State\RedisStateStore;

/**
 * In-memory Redis that records every command. The three Lua scripts of RedisStateStore are recognised by identity
 * and run with the semantics Redis gives them (a real server is covered by RedisStateStoreTest, WATCHER_TEST_REDIS).
 */
final class FakeRedisServer implements RedisClient
{
    /** @var array<string, array{0: string, 1: int|null}> value and absolute expiry in ms (null = none) */
    private array $data = [];

    /** @var list<list<scalar>> */
    public array $commands = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    private function nowMs(): int
    {
        return (int) $this->clock->now()->format('Uv'); // epoch seconds followed by milliseconds
    }

    private function sweep(): void
    {
        $now = $this->nowMs();
        foreach ($this->data as $k => [, $exp]) {
            if ($exp !== null && $exp <= $now) {
                unset($this->data[$k]);
            }
        }
    }

    public function get(string $key): ?string
    {
        $this->commands[] = ['GET', $key];
        $this->sweep();

        return $this->data[$key][0] ?? null;
    }

    public function set(string $key, string $value, int $ttlMs, bool $onlyIfAbsent = false): bool
    {
        $this->commands[] = ['SET', $key, $ttlMs, $onlyIfAbsent];
        $this->sweep();
        if ($onlyIfAbsent && isset($this->data[$key])) {
            return false;
        }
        $this->data[$key] = [$value, $this->nowMs() + $ttlMs];

        return true;
    }

    public function eval(string $script, array $keys, array $args): mixed
    {
        $this->commands[] = ['EVAL', $script, ...$keys, ...$args];
        $this->sweep();
        $key = $keys[0];
        switch ($script) {
            case RedisStateStore::RELEASE:
                if (($this->data[$key][0] ?? null) === $args[0]) {
                    unset($this->data[$key]);

                    return 1;
                }

                return 0;
            case RedisStateStore::RENEW:
                if (($this->data[$key][0] ?? null) === $args[0]) {
                    $this->data[$key][1] = $this->nowMs() + (int) $args[1];

                    return 1;
                }

                return 0;
            case RedisStateStore::INCREMENT:
                $new = (float) ($this->data[$key][0] ?? 0) + (float) $args[0];
                $exp = $this->data[$key][1] ?? null;
                $this->data[$key] = [(string) $new, $exp ?? $this->nowMs() + (int) $args[1]];

                return (string) $new;
            case RedisStateStore::RESERVE:
                $usd = (float) ($this->data[$keys[0]][0] ?? 0);
                $tok = (float) ($this->data[$keys[1]][0] ?? 0);
                if ($usd + (float) $args[0] > (float) $args[2] || ((float) $args[3] >= 0 && $tok + (float) $args[1] > (float) $args[3])) {
                    return 0;
                }
                foreach ([0, 1] as $i) {
                    $exp = $this->data[$keys[$i]][1] ?? null;
                    $this->data[$keys[$i]] = [(string) ((float) ($this->data[$keys[$i]][0] ?? 0) + (float) $args[$i]), $exp ?? $this->nowMs() + (int) $args[4]];
                }

                return 1;
        }
        throw new \LogicException('unknown script');
    }

    public function pttl(string $key): int
    {
        $this->sweep();
        if (!isset($this->data[$key])) {
            return -2;
        }

        return $this->data[$key][1] === null ? -1 : $this->data[$key][1] - $this->nowMs();
    }

    /** @return list<string> */
    public function keys(): array
    {
        $this->sweep();

        return array_map('strval', array_keys($this->data));
    }

    /** Test seam: plants a raw value the store did not write (a legacy or corrupt entry). */
    public function plant(string $key, string $raw, int $ttlMs = 60000): void
    {
        $this->data[$key] = [$raw, $this->nowMs() + $ttlMs];
    }

    public function raw(string $key): ?string
    {
        return $this->data[$key][0] ?? null;
    }
}
