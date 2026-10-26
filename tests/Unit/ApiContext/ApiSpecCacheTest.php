<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\ApiContext\ApiSpecCache;
use Watcher\ApiContext\OperationSummary;
use Watcher\ApiContext\ReducedSpec;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RecordingStateStore;

final class ApiSpecCacheTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryStateStore $inner;
    private RecordingStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00Z'));
        $this->inner = new InMemoryStateStore($this->clock);
        $this->store = new RecordingStateStore($this->inner);
    }

    private function spec(): ReducedSpec
    {
        return new ReducedSpec('/api', [new OperationSummary('GET', '/a/{id}', 'A', 't', false, '')], 3);
    }

    public function testPutThenGetWithinTheTtl(): void
    {
        $cache = new ApiSpecCache($this->store, $this->clock, 3600);
        self::assertNull($cache->get('orders', 'abc'));
        $cache->put('orders', 'abc', $this->spec());

        self::assertEquals($this->spec(), $cache->get('orders', 'abc'));
        $entry = $this->inner->all()[Keys::apiContext('orders', 'abc')];
        self::assertSame('2026-10-24T06:00:00+00:00', $entry['readAt']);
        self::assertSame(3600, $this->inner->ttls()[Keys::apiContext('orders', 'abc')]);
        self::assertSame(3, $entry['dropped']);
    }

    public function testExpiresAfterTheTtl(): void
    {
        $cache = new ApiSpecCache($this->store, $this->clock, 3600);
        $cache->put('orders', 'abc', $this->spec());
        $this->clock->advance('+3601 seconds');
        self::assertNull($cache->get('orders', 'abc'));
    }

    public function testKeyDiffersPerServiceAndSource(): void
    {
        $cache = new ApiSpecCache($this->store, $this->clock, 60);
        $cache->put('orders', 'aaa', $this->spec());
        self::assertNull($cache->get('billing', 'aaa'));
        self::assertNull($cache->get('orders', 'bbb'));
        self::assertSame(['api:orders:aaa'], $this->store->putKeys());
    }

    public function testCorruptOrOldEntryIsAMiss(): void
    {
        $this->inner->put(Keys::apiContext('orders', 'abc'), ['v' => 0, 'ops' => 'x'], 60);
        self::assertNull((new ApiSpecCache($this->store, $this->clock, 60))->get('orders', 'abc'));
    }

    public function testNonPositiveTtlIsRefusedNamingTheKeyKind(): void
    {
        foreach ([0, -1, -3600] as $ttl) {
            try {
                new ApiSpecCache($this->store, $this->clock, $ttl);
                self::fail("ttl $ttl accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString(Keys::API, $e->getMessage());
            }
        }
    }

    public function testEveryWriteCarriesAPositiveTtl(): void
    {
        $cache = new ApiSpecCache($this->store, $this->clock, 3600);
        $cache->put('orders', 'abc', $this->spec());
        self::assertSame([Keys::apiContext('orders', 'abc')], $this->store->putKeys());
        foreach ($this->inner->ttls() as $left) {
            self::assertGreaterThan(0, $left);
        }
    }
}
