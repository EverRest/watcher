<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use Watcher\State\Redis\PhpRedisClient;
use Watcher\State\Redis\PredisClient;
use Watcher\State\RedisStateStore;
use Watcher\Tests\Fakes\FakePredis;
use Watcher\Tests\Fakes\FakeRedisServer;
use Watcher\Tests\Fakes\FrozenClock;

/**
 * Owner rule: every Redis write carries a TTL. After ANY sequence of put / increment / acquire / renew / release,
 * no key may exist without one. Run against the recording server, the Predis adapter and (WATCHER_TEST_REDIS) a real server.
 */
final class RedisTtlContractTest extends TestCase
{
    /** @return array<string, array{Closure(): array{RedisStateStore, Closure(): list<int>, Closure(): void}}> */
    public static function backends(): array
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));

        return [
            'recording server' => [static function () use ($clock): array {
                $server = new FakeRedisServer($clock);

                return [new RedisStateStore($server, $clock), static fn (): array => array_map($server->pttl(...), $server->keys()), static function (): void {
                }];
            }],
            'predis adapter' => [static function () use ($clock): array {
                $server = new FakeRedisServer($clock);

                return [new RedisStateStore(new PredisClient(new FakePredis($server)), $clock), static fn (): array => array_map($server->pttl(...), $server->keys()), static function (): void {
                }];
            }],
            'real redis' => [static function () use ($clock): array {
                $dsn = getenv('WATCHER_TEST_REDIS');
                if ($dsn === false || $dsn === '') {
                    self::markTestSkipped('WATCHER_TEST_REDIS (host:port) not set');
                }
                [$host, $port] = array_pad(explode(':', $dsn, 2), 2, '6379');
                $redis = new Redis();
                $redis->connect($host, (int) $port);
                $prefix = 'watcher-test:' . bin2hex(random_bytes(6)) . ':';

                return [
                    new RedisStateStore(new PhpRedisClient($redis), $clock, $prefix),
                    static fn (): array => array_map(static fn (string $k): int => (int) $redis->pttl($k), array_map('strval', (array) $redis->keys($prefix . '*'))),
                    static function () use ($redis, $prefix): void {
                        foreach ((array) $redis->keys($prefix . '*') as $k) {
                            $redis->del($k);
                        }
                    },
                ];
            }],
        ];
    }

    /** @param Closure(): array{RedisStateStore, Closure(): list<int>, Closure(): void} $make */
    #[DataProvider('backends')]
    public function testNoKeyEverExistsWithoutATtl(Closure $make): void
    {
        [$store, $ttls, $cleanup] = $make();
        mt_srand(42);
        $keys = ['a', 'b', 'c', 'run:svc:1', 'spend:svc:2026-01-01:usd'];
        try {
            for ($i = 0; $i < 300; $i++) {
                $key = $keys[mt_rand(0, count($keys) - 1)];
                match (mt_rand(0, 5)) {
                    0 => $store->put($key, ['n' => $i], mt_rand(1, 900)),
                    1 => $store->increment('cnt:' . $key, 0.5, mt_rand(1, 900)),
                    2 => $store->acquire('lock:' . $key, 'o' . mt_rand(0, 2), mt_rand(1, 900)),
                    3 => $store->renew('lock:' . $key, 'o' . mt_rand(0, 2), mt_rand(1, 900)),
                    4 => $store->release('lock:' . $key, 'o' . mt_rand(0, 2)),
                    5 => $store->put($key, ['blob' => str_repeat('x', 2000)], mt_rand(1, 900)), // compressed value
                };
                foreach ($ttls() as $pttl) {
                    self::assertGreaterThan(0, $pttl, 'a key exists without a TTL after step ' . $i);
                }
            }
        } finally {
            $cleanup();
        }
        self::assertNotSame([], $ttls(), 'the sequence left nothing to check');
    }
}
