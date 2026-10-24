<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\State\RedisStateStore;
use Watcher\State\ReservableCounters;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FakeRedisServer;
use Watcher\Tests\Fakes\FrozenClock;

/** A reservation is settled or released once: a store failure half way through must leave an over-count, never an under-count. */
final class SpendRetrySafetyTest extends TestCase
{
    public function testAFailureBetweenTheTwoIncrementsOfASettleIsNotReturnedAgainByTheRelease(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-23T10:00:00+00:00'));
        $inner = new RedisStateStore(new FakeRedisServer($clock), $clock);
        $store = new class ($inner) implements StateStore, ReservableCounters {
            public int $failOnIncrement = 0;

            public function __construct(private readonly RedisStateStore $inner)
            {
            }

            public function get(string $key): ?array
            {
                return $this->inner->get($key);
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
                $this->inner->put($key, $value, $ttlSeconds);
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                return $this->inner->acquire($key, $owner, $ttlSeconds);
            }

            public function release(string $key, string $owner): void
            {
                $this->inner->release($key, $owner);
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                if ($this->failOnIncrement > 0 && --$this->failOnIncrement === 0) {
                    throw new RuntimeException('redis went away');
                }

                return $this->inner->increment($key, $by, $ttlSeconds);
            }

            public function reserve(string $usdKey, string $tokensKey, float $usd, float $tokens, float $usdCap, ?float $tokenCap, int $ttlSeconds): ?bool
            {
                return $this->inner->reserve($usdKey, $tokensKey, $usd, $tokens, $usdCap, $tokenCap, $ttlSeconds);
            }
        };
        $spend = new Spend($store, $clock, new DateTimeZone('UTC'), 'svc');
        self::assertTrue($spend->reserve(0.50, 10000, 1.0, null));

        $store->failOnIncrement = 2; // the USD increment lands, the token increment fails
        try {
            $spend->add(new Usage('m', 1000, 500), 0.05);
            self::fail('the store failure must surface');
        } catch (RuntimeException) {
        }
        $spend->release(); // what the Watcher's finally does

        self::assertEqualsWithDelta(0.05, $spend->today()['usd'], 1e-9, 'the reserved worst case was returned once, not twice');
        self::assertGreaterThanOrEqual(0, $spend->today()['tokens']);
    }
}
