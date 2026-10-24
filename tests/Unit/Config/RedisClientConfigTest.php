<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\State\Redis\PhpRedisClient;
use Watcher\State\Redis\PredisClient;
use Watcher\Tests\Fakes\FakeRedisServer;
use Watcher\Tests\Fakes\FrozenClock;

final class RedisClientConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $redis
     * @return array<string, mixed>
     */
    private static function base(array $redis): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.test', 'auth' => ['type' => 'bearer', 'token' => 't']]],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'redis', 'redis' => $redis],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/X']],
        ];
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function accepted(): array
    {
        return [
            'defaults' => [[]],
            'auto' => [['client' => 'auto']],
            'phpredis' => [['client' => 'phpredis']],
            'predis' => [['client' => 'predis']],
            'read timeout (documented, was rejected as an unknown key)' => [['read_timeout' => 2.5]],
            'compression off' => [['compress_min_bytes' => 0]],
            'compression threshold' => [['compress_min_bytes' => 4096]],
        ];
    }

    /** @param array<string, mixed> $redis */
    #[DataProvider('accepted')]
    public function testAccepts(array $redis): void
    {
        self::assertSame('redis', WatchConfig::fromArray(self::base($redis))->state['driver']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function rejected(): array
    {
        return [
            'unknown client' => [['client' => 'memcached'], 'state.redis.client'],
            'client not a string' => [['client' => 3], 'state.redis.client'],
            'read timeout not positive' => [['read_timeout' => -1], 'state.redis.read_timeout'],
            'negative threshold' => [['compress_min_bytes' => -1], 'state.redis.compress_min_bytes'],
            'string threshold' => [['compress_min_bytes' => '512'], 'state.redis.compress_min_bytes'],
        ];
    }

    /** @param array<string, mixed> $redis */
    #[DataProvider('rejected')]
    public function testRejects(array $redis, string $key): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage($key);
        WatchConfig::fromArray(self::base($redis));
    }

    public function testAnInjectedRedisClientIsAccepted(): void
    {
        $server = new FakeRedisServer(new FrozenClock(new DateTimeImmutable()));

        self::assertSame('redis', WatchConfig::fromArray(self::base(['instance' => $server]))->state['driver']);
    }

    public function testTheFactoryUsesAnInjectedRedisClientAsIs(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable());
        $server = new FakeRedisServer($clock);
        $store = Factory::state(WatchConfig::fromArray(self::base(['instance' => $server])), $clock);

        $store->put('k', ['a' => 1], 60);

        self::assertSame(['watcher:k'], $server->keys());
    }

    /** @return array<string, array{string, bool, bool, string}> */
    public static function picks(): array
    {
        return [
            'auto prefers the extension' => ['auto', true, true, 'phpredis'],
            'auto falls back to predis' => ['auto', false, true, 'predis'],
            'explicit predis wins over the extension' => ['predis', true, true, 'predis'],
            'explicit phpredis' => ['phpredis', true, false, 'phpredis'],
        ];
    }

    #[DataProvider('picks')]
    public function testClientSelection(string $mode, bool $ext, bool $predis, string $expected): void
    {
        self::assertSame($expected, Factory::pickRedisClient($mode, $ext, $predis));
    }

    /** @return array<string, array{string, bool, bool, string}> */
    public static function missing(): array
    {
        return [
            'nothing installed' => ['auto', false, false, 'ext-redis or predis/predis'],
            'phpredis asked, absent' => ['phpredis', false, true, 'ext-redis'],
            'predis asked, absent' => ['predis', true, false, 'predis/predis'],
        ];
    }

    #[DataProvider('missing')]
    public function testSelectionNamesWhatIsMissing(string $mode, bool $ext, bool $predis, string $needle): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($needle);
        Factory::pickRedisClient($mode, $ext, $predis);
    }

    public function testAPredisShapedObjectIsWrappedByTheAdapter(): void
    {
        self::assertInstanceOf(PredisClient::class, Factory::wrapRedisInstance(new \Watcher\Tests\Fakes\FakePredis(new FakeRedisServer(new FrozenClock(new DateTimeImmutable())))));
        self::assertInstanceOf(PhpRedisClient::class, Factory::wrapRedisInstance($this->createMock(\Redis::class)));
    }
}
