<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeAnalyzer;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Watcher;

final class WatcherFromConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'service' => 'svc',
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid', 'auth' => ['type' => 'bearer', 'token' => 't']]],
            'analysis' => ['api_key' => 'k', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], ],
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

    private const BAD_PDO = ['driver' => 'pdo', 'pdo' => ['dsn' => 'sqlite:/nonexistent-watcher-dir-7f3a/secret-marker.db', 'username' => 'dbuser', 'password' => 'hunter2-secret']];

    public function testPdoConnectFailureWaitsForTheFirstStateAccessAndLeaksNothing(): void
    {
        $dsn = self::BAD_PDO['pdo']['dsn'];
        $watcher = Watcher::fromConfig(array_replace(self::valid(), ['state' => self::BAD_PDO])); // builds: nothing connected yet
        self::assertInstanceOf(Watcher::class, $watcher);

        $store = Factory::state(WatchConfig::fromArray(array_replace(self::valid(), ['state' => self::BAD_PDO])), new FrozenClock(new DateTimeImmutable('2026-10-23T12:00:00Z')));
        try {
            $store->get('any');
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame('state.pdo: cannot connect', $e->getMessage());
            self::assertNull($e->getPrevious());
            foreach ([$dsn, 'secret-marker', 'hunter2-secret', 'dbuser'] as $secret) {
                self::assertStringNotContainsString($secret, (string) $e);
            }
        }
    }

    public function testPdoStateIsNotTouchedOnATriggerOutsideTheRunHour(): void
    {
        $watcher = Watcher::fromConfig(array_replace(self::valid(), ['state' => self::BAD_PDO]));

        self::assertSame(RunOutcome::SkippedNotDue, $watcher->run(new DateTimeImmutable('2026-10-23T12:00:00Z'))->outcome);
    }

    public function testPdoConnectFailureOnADueRunIsAFailedRunWithANotice(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-23T06:00:00Z'));
        $c = WatchConfig::fromArray(array_replace(self::valid(), ['state' => self::BAD_PDO]));
        $notifier = new FakeNotifier();
        $watcher = new Watcher($c, new FakeLogSource(), Factory::state($c, $clock), new FakeAnalyzer(), $notifier, $clock);

        $result = $watcher->run();

        self::assertSame(RunOutcome::Failed, $result->outcome);
        self::assertCount(1, $notifier->sent);
        foreach (['secret-marker', 'hunter2-secret', 'dbuser'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $result->failureReason);
            self::assertStringNotContainsString($secret, $notifier->sent[0]["message"]->text);
        }
    }

    public function testRedisConnectFailureWaitsForTheFirstStateAccess(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis missing');
        }
        $state = ['driver' => 'redis', 'redis' => ['host' => '127.0.0.1', 'port' => 1]];
        $clock = new class () implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-23T12:00:00Z');
            }
        };
        $store = Factory::state(WatchConfig::fromArray(array_replace(self::valid(), ['state' => $state])), $clock);

        $this->expectException(RuntimeException::class);
        $store->get('any');
    }

    public function testRedisStateIsNotTouchedOnATriggerOutsideTheRunHour(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis missing');
        }
        $state = ['driver' => 'redis', 'redis' => ['host' => '127.0.0.1', 'port' => 1]];
        $watcher = Watcher::fromConfig(array_replace(self::valid(), ['state' => $state]));

        self::assertSame(RunOutcome::SkippedNotDue, $watcher->run(new DateTimeImmutable('2026-10-23T12:00:00Z'))->outcome);
    }

    public function testInvalidConfigThrowsBeforeAnyBuild(): void
    {
        $this->expectException(InvalidConfig::class);
        Watcher::fromConfig(['service' => 'svc', 'enabled' => true]);
    }
}
