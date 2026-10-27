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
            'prefix' => [['prefix' => 'prod:']],
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
            'empty prefix' => [['prefix' => ''], 'state.redis.prefix'],
            'prefix not a string' => [['prefix' => 5], 'state.redis.prefix'],
            'prefix with a glob or space' => [['prefix' => 'a *b'], 'state.redis.prefix'],
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
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis is not loaded');
        }
        self::assertInstanceOf(PredisClient::class, Factory::wrapRedisInstance(new \Watcher\Tests\Fakes\FakePredis(new FakeRedisServer(new FrozenClock(new DateTimeImmutable())))));
        $redis = $this->createMock(\Redis::class);
        $redis->method('getOption')->willReturn(\Redis::SERIALIZER_NONE);
        self::assertInstanceOf(PhpRedisClient::class, Factory::wrapRedisInstance($redis));
    }

    public function testAFailedConnectionKeepsThePasswordOutOfTheTraceAndItsArguments(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis');
        }
        $password = 'hunter2-TRACE-SECRET';
        $c = WatchConfig::fromArray(self::base(['client' => 'phpredis', 'host' => '127.0.0.1', 'port' => 1, 'password' => $password, 'username' => 'u']));
        $store = Factory::state($c, new FrozenClock(new DateTimeImmutable()));
        ini_set('zend.exception_ignore_args', '0');
        try {
            $store->get('heartbeat');
            self::fail('port 1 must refuse');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString($password, $e->getTraceAsString());
            $args = '';
            foreach ($e->getTrace() as $frame) {
                $class = $frame['class'] ?? '';
                if (str_starts_with($class, 'Watcher\\') && !str_contains($class, 'Tests')) {
                    $args .= print_r($frame['args'] ?? [], true);
                }
            }
            self::assertStringContainsString('Watcher', (string) $e->getTraceAsString()); // the frames were inspected
            self::assertNotSame('', $args);
            self::assertStringNotContainsString($password, $args);
        }
    }

    public function testTheLazyRedisStoreReportsTheUnreadableEntriesOfTheStoreBehindIt(): void
    {
        $c = WatchConfig::fromArray(self::base(['instance' => $server = new FakeRedisServer(new FrozenClock(new DateTimeImmutable()))]));
        $store = Factory::state($c, new FrozenClock(new DateTimeImmutable()));
        self::assertInstanceOf(\Watcher\State\UnreadableCounter::class, $store);
        self::assertSame(0, $store->unreadableEntries());
        $server->plant('watcher:corrupt', 'z1:not zlib');
        $store->get('corrupt');
        self::assertSame(1, $store->unreadableEntries());
    }

    public function testThePrefixSeparatesEnvironmentsOnOneDatabase(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable());
        $server = new FakeRedisServer($clock);
        Factory::state(WatchConfig::fromArray(self::base(['instance' => $server, 'prefix' => 'prod:'])), $clock)->put('k', ['a' => 1], 60);
        Factory::state(WatchConfig::fromArray(self::base(['instance' => $server, 'prefix' => 'stg:'])), $clock)->put('k', ['a' => 2], 60);

        self::assertEqualsCanonicalizing(['prod:k', 'stg:k'], $server->keys());
    }
}
