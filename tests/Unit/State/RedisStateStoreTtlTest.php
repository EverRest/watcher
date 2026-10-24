<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\State;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\State\RedisStateStore;
use Watcher\Tests\Fakes\FakeRedisServer;
use Watcher\Tests\Fakes\FrozenClock;

final class RedisStateStoreTtlTest extends TestCase
{
    private FakeRedisServer $server;

    private RedisStateStore $store;

    protected function setUp(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->server = new FakeRedisServer($clock);
        $this->store = new RedisStateStore($this->server, $clock, 'p:');
    }

    /** @return array<string, array{int}> */
    public static function badTtls(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    #[DataProvider('badTtls')]
    public function testPutRefusesANonPositiveTtlNamingTheKindNotTheKey(int $ttl): void
    {
        try {
            $this->store->put('run:secret-service:2026', ['a' => 1], $ttl);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString("'run'", $e->getMessage());
            self::assertStringNotContainsString('secret-service', $e->getMessage());
        }
        self::assertSame([], $this->server->keys());
    }

    #[DataProvider('badTtls')]
    public function testEveryOtherWriterRefusesToo(int $ttl): void
    {
        foreach ([
            fn () => $this->store->increment('spend:x', 1.0, $ttl),
            fn () => $this->store->acquire('lock:x', 'o', $ttl),
            fn () => $this->store->renew('lock:x', 'o', $ttl),
        ] as $call) {
            try {
                $call();
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->server->keys());
    }

    public function testNonKindKeysAreReportedAsUnknown(): void
    {
        $this->expectExceptionMessageMatches("/'unknown'/");
        $this->store->put("../../evil\0key", ['a' => 1], 0);
    }

    public function testWritesUseMillisecondExpiry(): void
    {
        $this->store->put('k', ['a' => 1], 60);
        $this->store->acquire('l', 'o', 30);
        self::assertSame(['SET', 'p:k', 60000, false], $this->server->commands[0]);
        self::assertSame(['SET', 'p:l', 30000, true], $this->server->commands[1]);
    }

    public function testIncrementAndRenewScriptsSetTheExpiryInTheSameScript(): void
    {
        self::assertStringContainsString('INCRBYFLOAT', RedisStateStore::INCREMENT);
        self::assertStringContainsString('PEXPIRE', RedisStateStore::INCREMENT);
        self::assertStringContainsString('PEXPIRE', RedisStateStore::RENEW);
        $this->store->increment('x', 1.0, 2);
        self::assertSame(2000, $this->server->pttl('p:x'));
    }
}
