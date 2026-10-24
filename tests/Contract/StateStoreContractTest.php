<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Watcher\State\RenewableLock;
use Watcher\State\StateStore;

abstract class StateStoreContractTest extends TestCase
{
    private DateTimeImmutable $now;

    protected ClockInterface $clock;

    protected StateStore $store;

    abstract protected function makeStore(ClockInterface $clock): StateStore;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $now = &$this->now;
        $this->clock = new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable &$now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        $this->store = $this->makeStore($this->clock);
    }

    protected function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    public function testMissingKeyIsNull(): void
    {
        self::assertNull($this->store->get('nope'));
    }

    public function testPutGetRoundTrip(): void
    {
        $value = ['a' => 1, 'b' => ['x', 'y'], 'c' => null, 'd' => 1.5];
        $this->store->put('k', $value, 60);

        self::assertSame($value, $this->store->get('k'));
    }

    public function testPutOverwrites(): void
    {
        $this->store->put('k', ['v' => 1], 60);
        $this->store->put('k', ['v' => 2], 60);

        self::assertSame(['v' => 2], $this->store->get('k'));
    }

    public function testPutExpires(): void
    {
        $this->store->put('k', ['v' => 1], 10);
        $this->advance(9);
        self::assertSame(['v' => 1], $this->store->get('k'));
        $this->advance(1);
        self::assertNull($this->store->get('k'));
    }

    /** The longest key a store must accept; the PDO column is VARCHAR(191) and refuses more (A4-14). */
    protected function longKeyLength(): int
    {
        return 500;
    }

    public function testUnusualKeysAreSafe(): void
    {
        foreach (['../../evil', 'a/b', "nul\0byte", str_repeat('k', $this->longKeyLength()), 'ключ'] as $key) {
            $this->store->put($key, ['k' => $key], 60);
            self::assertSame(['k' => $key], $this->store->get($key));
        }
    }

    public function testAcquireIsExclusiveUntilTtl(): void
    {
        self::assertTrue($this->store->acquire('lock', 'a', 30));
        self::assertFalse($this->store->acquire('lock', 'b', 30));
        self::assertFalse($this->store->acquire('lock', 'a', 30));
        $this->advance(30);
        self::assertTrue($this->store->acquire('lock', 'b', 30));
    }

    public function testReleaseByOwnerFreesLock(): void
    {
        $this->store->acquire('lock', 'a', 30);
        $this->store->release('lock', 'a');

        self::assertTrue($this->store->acquire('lock', 'b', 30));
    }

    public function testReleaseByNonOwnerDoesNotFreeLock(): void
    {
        $this->store->acquire('lock', 'a', 30);
        $this->store->release('lock', 'b');

        self::assertFalse($this->store->acquire('lock', 'b', 30));
    }

    public function testReleaseOfMissingLockIsNoop(): void
    {
        $this->store->release('missing', 'a');

        self::assertTrue($this->store->acquire('missing', 'a', 30));
    }

    public function testIncrementAccumulatesAndReturnsNewValue(): void
    {
        self::assertSame(1.5, $this->store->increment('spend', 1.5, 60));
        self::assertSame(2.0, $this->store->increment('spend', 0.5, 60));
        self::assertSame(2.25, $this->store->increment('spend', 0.25, 60));
    }

    public function testIncrementIsReadableAsTotal(): void
    {
        $this->store->increment('spend', 7.5, 60);
        // assertEquals: JSON stores round-trip 10.0 as int 10; only the numeric value matters to Spend.
        self::assertEquals(['total' => 7.5], $this->store->get('spend'));

        $this->store->increment('spend', 2.5, 60);
        self::assertEquals(['total' => 10.0], $this->store->get('spend'));
    }

    public function testIncrementExpires(): void
    {
        $this->store->increment('spend', 3.0, 10);
        $this->advance(10);

        self::assertSame(1.0, $this->store->increment('spend', 1.0, 10));
    }

    public function testRenewExtendsOnlyTheOwnersLive(): void
    {
        $store = $this->store;
        if (!$store instanceof RenewableLock) {
            self::markTestSkipped('store has no renew');
        }
        self::assertTrue($this->store->acquire('l', 'me', 10));
        $this->advance(8);
        self::assertTrue($store->renew('l', 'me', 10));
        $this->advance(5); // 13 s after acquire: would have expired without the renew
        self::assertFalse($this->store->acquire('l', 'other', 10));
        self::assertFalse($store->renew('l', 'other', 1000), 'a non-owner cannot renew');
        $this->advance(6); // 19 s: the renewed ttl (8 + 10 = 18 s) has passed, the non-owner call changed nothing
        self::assertTrue($this->store->acquire('l', 'other', 10));
    }

    public function testRenewOfALostLockReturnsFalse(): void
    {
        $store = $this->store;
        if (!$store instanceof RenewableLock) {
            self::markTestSkipped('store has no renew');
        }
        self::assertTrue($this->store->acquire('l', 'me', 5));
        $this->advance(6);
        self::assertFalse($store->renew('l', 'me', 5));
        self::assertTrue($this->store->acquire('l', 'other', 5));
    }
}
