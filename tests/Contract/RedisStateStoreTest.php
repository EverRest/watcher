<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Psr\Clock\ClockInterface;
use Redis;
use Watcher\State\RedisStateStore;
use Watcher\State\StateStore;

/**
 * Needs a real Redis: WATCHER_TEST_REDIS=host:port. Expiry is enforced by Redis, not the
 * injected clock, so the three clock-advance contract cases are re-stated below with real
 * 1-second TTLs and a short sleep.
 */
final class RedisStateStoreTest extends StateStoreContractTest
{
    private Redis $redis;

    private string $prefix;

    protected function makeStore(ClockInterface $clock): StateStore
    {
        $dsn = getenv('WATCHER_TEST_REDIS');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('WATCHER_TEST_REDIS (host:port) not set');
        }
        [$host, $port] = array_pad(explode(':', $dsn, 2), 2, '6379');
        $this->redis = new Redis();
        $this->redis->connect($host, (int) $port);
        $this->prefix = 'watcher-test:' . bin2hex(random_bytes(6)) . ':';

        return new RedisStateStore($this->redis, $clock, $this->prefix);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            foreach ($this->redis->keys($this->prefix . '*') as $key) {
                $this->redis->del($key);
            }
        }
    }

    public function testPutExpires(): void
    {
        $this->store->put('k', ['v' => 1], 1);
        self::assertSame(['v' => 1], $this->store->get('k'));
        usleep(1_300_000);
        self::assertNull($this->store->get('k'));
    }

    public function testAcquireIsExclusiveUntilTtl(): void
    {
        self::assertTrue($this->store->acquire('lock', 'a', 1));
        self::assertFalse($this->store->acquire('lock', 'b', 1));
        self::assertFalse($this->store->acquire('lock', 'a', 1));
        usleep(1_300_000);
        self::assertTrue($this->store->acquire('lock', 'b', 1));
    }

    public function testIncrementExpires(): void
    {
        $this->store->increment('spend', 3.0, 1);
        usleep(1_300_000);

        self::assertSame(1.0, $this->store->increment('spend', 1.0, 1));
    }

    public function testEveryKeyCarriesAnExpiry(): void
    {
        $this->store->put('p', ['v' => 1], 60);
        $this->store->acquire('l', 'a', 60);
        $this->store->increment('i', 1.0, 60);

        foreach (['p', 'l', 'i'] as $key) {
            self::assertGreaterThan(0, $this->redis->ttl($this->prefix . $key));
        }
    }

    public function testIncrementDoesNotExtendTtl(): void
    {
        $this->store->increment('spend', 1.0, 2);
        $this->store->increment('spend', 1.0, 100);

        self::assertLessThanOrEqual(2, $this->redis->ttl($this->prefix . 'spend'));
    }

    public function testRenewExtendsOnlyTheOwnersLive(): void
    {
        $store = $this->store;
        self::assertInstanceOf(\Watcher\State\RenewableLock::class, $store);
        self::assertTrue($store->acquire('l', 'me', 2));
        sleep(1);
        self::assertTrue($store->renew('l', 'me', 2));
        usleep(1_500_000); // 2.5 s after acquire: would have expired without the renew
        self::assertFalse($store->acquire('l', 'other', 2));
        self::assertFalse($store->renew('l', 'other', 1000), 'a non-owner cannot renew');
        usleep(2_200_000);
        self::assertTrue($store->acquire('l', 'other', 2));
    }

    public function testRenewOfALostLockReturnsFalse(): void
    {
        $store = $this->store;
        self::assertInstanceOf(\Watcher\State\RenewableLock::class, $store);
        self::assertTrue($store->acquire('l', 'me', 1));
        usleep(1_300_000);
        self::assertFalse($store->renew('l', 'me', 1));
        self::assertTrue($store->acquire('l', 'other', 1));
    }
}
