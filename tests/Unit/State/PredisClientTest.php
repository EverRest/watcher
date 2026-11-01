<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\State;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\State\Redis\PredisClient;
use Watcher\Tests\Fakes\FakePredis;
use Watcher\Tests\Fakes\FakeRedisServer;
use Watcher\Tests\Fakes\FrozenClock;

final class PredisClientTest extends TestCase
{
    private FakeRedisServer $server;

    private PredisClient $client;

    protected function setUp(): void
    {
        $this->server = new FakeRedisServer(new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z')));
        $this->client = new PredisClient(new FakePredis($this->server));
    }

    public function testSetWithPxAndNx(): void
    {
        self::assertTrue($this->client->set('k', 'v', 5000, true));
        self::assertFalse($this->client->set('k', 'w', 5000, true));
        self::assertTrue($this->client->set('k', 'w', 9000));
        self::assertSame('w', $this->client->get('k'));
        self::assertSame(9000, $this->client->pttl('k'));
        self::assertNull($this->client->get('missing'));
        self::assertSame(-2, $this->client->pttl('missing'));
    }

    public function testSetRefusesAMissingTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client->set('k', 'v', 0);
    }

    public function testEvalPassesKeysThenArgs(): void
    {
        $this->client->set('k', '"me"', 5000);

        self::assertSame(1, $this->client->eval(\Watcher\State\RedisStateStore::RELEASE, ['k'], ['"me"']));
        self::assertNull($this->client->get('k'));
    }

    public function testADriverErrorNeverReachesTheMessage(): void
    {
        $client = new PredisClient(new FakePredis($this->server, new \Exception('AUTH failed for redis://:hunter2@cache:6379')));

        try {
            $client->get('k');
            self::fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('hunter2', $e->getMessage());
            self::assertNull($e->getPrevious(), 'not chained: the previous message carries the password');
        }
    }

    public function testChoosingPredisWithoutItInstalledFailsOnFirstUseNotAtBuild(): void
    {
        if (class_exists('Predis\Client')) {
            self::markTestSkipped('predis/predis is installed');
        }
        $config = WatchConfig::fromArray([
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.test', 'auth' => ['type' => 'bearer', 'token' => 't']]],
            'analysis' => ['api_keys' => ['gemini' => 'k'], 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'], ],
            'state' => ['driver' => 'redis', 'redis' => ['client' => 'predis', 'password' => 'hunter2']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/X']],
        ]);
        $store = Factory::state($config, new FrozenClock(new DateTimeImmutable()));

        try {
            $store->get('k');
            self::fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('predis/predis', $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function testAnErrorObjectInsteadOfAReplyFailsClosedNotAsMissing(): void
    {
        $client = new PredisClient(new class () {
            public function get(string $key): object
            {
                return new \stdClass(); // what Predis returns for a server error when exceptions are off
            }
        });

        $this->expectException(RuntimeException::class);
        $client->get('k');
    }
}
