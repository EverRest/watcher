<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Watcher;

final class WatcherFromConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'http://loki.invalid', 'auth' => ['type' => 'bearer', 'token' => 't']]],
            'analysis' => ['api_key' => 'k', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], 'prices' => ['a' => [1, 5, 0.1, 1.25], 'b' => [3, 15, 0.3, 3.75], 'c' => [5, 25, 0.5, 6.25]]],
            'state' => ['driver' => 'file', 'file' => ['path' => sys_get_temp_dir() . '/watcher-from-config-test']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
        ];
    }

    public function testValidMinimalConfigBuildsWithoutNetwork(): void
    {
        self::assertInstanceOf(Watcher::class, Watcher::fromConfig(self::valid()));
    }

    public function testDatadogSourceBuildsWithoutNetwork(): void
    {
        $dd = ['driver' => 'datadog', 'datadog' => ['site' => 'datadoghq.eu', 'api_key' => 'a', 'app_key' => 'b']];

        self::assertInstanceOf(Watcher::class, Watcher::fromConfig(array_replace(self::valid(), ['source' => $dd])));
    }

    public function testPdoStateBuildsOnSqliteAndCreatesNothing(): void
    {
        $state = ['driver' => 'pdo', 'pdo' => ['dsn' => 'sqlite::memory:', 'table' => 'my_state']];

        self::assertInstanceOf(Watcher::class, Watcher::fromConfig(array_replace(self::valid(), ['state' => $state])));
    }

    public function testRedisStateBuildsFromAnInjectedInstance(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis missing');
        }
        $state = ['driver' => 'redis', 'redis' => ['instance' => $this->createMock(\Redis::class)]];

        self::assertInstanceOf(Watcher::class, Watcher::fromConfig(array_replace(self::valid(), ['state' => $state])));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function rejected(): array
    {
        return [
            'redis instance not a Redis' => [['state' => ['driver' => 'redis', 'redis' => ['instance' => new \stdClass()]]], 'state.redis.instance'],
            'redis bad port' => [['state' => ['driver' => 'redis', 'redis' => ['port' => 70000]]], 'state.redis.port'],
            'redis bad database' => [['state' => ['driver' => 'redis', 'redis' => ['host' => 'r', 'database' => -1]]], 'state.redis.database'],
            'redis bad host' => [['state' => ['driver' => 'redis', 'redis' => ['host' => '']]], 'state.redis.host'],
            'pdo bad table' => [['state' => ['driver' => 'pdo', 'pdo' => ['dsn' => 'sqlite::memory:', 'table' => 'a;b']]], 'state.pdo.table'],
            'pdo bad username' => [['state' => ['driver' => 'pdo', 'pdo' => ['dsn' => 'sqlite::memory:', 'username' => 5]]], 'state.pdo.username'],
            'datadog bad site' => [['source' => ['driver' => 'datadog', 'datadog' => ['site' => 'evil.com/x', 'api_key' => 'a', 'app_key' => 'b']]], 'source.datadog.site'],
            'unknown state driver' => [['state' => ['driver' => 'memcached']], 'state.driver'],
            'unknown source driver' => [['source' => ['driver' => 'elk']], 'source.driver'],
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('rejected')]
    public function testBadDriverSettingsAreRejectedByKey(array $patch, string $expected): void
    {
        try {
            Watcher::fromConfig(array_replace(self::valid(), $patch));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testInvalidConfigThrowsBeforeAnyBuild(): void
    {
        $this->expectException(InvalidConfig::class);
        Watcher::fromConfig(['service' => 'svc']);
    }
}
