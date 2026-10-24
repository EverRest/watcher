<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\State;

use PHPUnit\Framework\TestCase;
use Redis;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\State\Redis\PhpRedisClient;

/** A serializer on an injected \Redis would serialize lock owners: renew/release compare raw strings in Lua and silently fail. */
final class PhpRedisClientSerializerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Redis::class)) {
            self::markTestSkipped('ext-redis');
        }
    }

    private static function redis(int $serializer): Redis
    {
        return new class ($serializer) extends Redis {
            public function __construct(private readonly int $serializer)
            {
            }

            /** @param int $option */
            public function getOption($option): mixed
            {
                return $option === Redis::OPT_SERIALIZER ? $this->serializer : 0;
            }
        };
    }

    public function testASerializerIsRefusedWhenWrapping(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('state.redis.instance must not use a serializer');
        Factory::wrapRedisInstance(self::redis(Redis::SERIALIZER_PHP));
    }

    public function testTheClientConstructorRefusesItToo(): void
    {
        $this->expectException(InvalidConfig::class);
        new PhpRedisClient(self::redis(Redis::SERIALIZER_JSON));
    }

    public function testNoSerializerIsAccepted(): void
    {
        self::assertInstanceOf(PhpRedisClient::class, Factory::wrapRedisInstance(self::redis(Redis::SERIALIZER_NONE)));
    }

    public function testAnInjectedCompressionIsRefusedToo(): void
    {
        $r = new class () extends Redis {
            public function __construct()
            {
            }

            /** @param int $option */
            public function getOption($option): mixed
            {
                return $option === Redis::OPT_COMPRESSION ? Redis::COMPRESSION_ZSTD : 0;
            }
        };

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('must not use a serializer or compression');
        new PhpRedisClient($r);
    }

    public function testATransportErrorOnGetFailsClosedButAMissingKeyIsNull(): void
    {
        $make = static fn (?string $error): PhpRedisClient => new PhpRedisClient(new class ($error) extends Redis {
            public function __construct(private readonly ?string $error)
            {
            }

            /** @param int $option */
            public function getOption($option): mixed
            {
                return 0;
            }

            public function get($key): mixed
            {
                return false; // phpredis answers false for a missing key AND for a failed command
            }

            public function getLastError(): ?string
            {
                return $this->error;
            }

            public function clearLastError(): bool
            {
                return true;
            }
        });

        self::assertNull($make(null)->get('k'));
        $this->expectException(\RuntimeException::class);
        $make('NOAUTH Authentication required')->get('k');
    }
}
