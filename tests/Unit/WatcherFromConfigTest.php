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
            'analysis' => ['api_key' => 'k', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c']],
            'state' => ['driver' => 'file', 'file' => ['path' => sys_get_temp_dir() . '/watcher-from-config-test']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
        ];
    }

    public function testValidMinimalConfigBuildsWithoutNetwork(): void
    {
        self::assertInstanceOf(Watcher::class, Watcher::fromConfig(self::valid()));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unavailable(): array
    {
        return [
            'datadog source' => [['source' => ['driver' => 'datadog', 'datadog' => ['api_key' => 'a', 'app_key' => 'b']]], "source.driver 'datadog'"],
            'redis state' => [['state' => ['driver' => 'redis']], "state.driver 'redis'"],
            'pdo state' => [['state' => ['driver' => 'pdo', 'pdo' => ['dsn' => 'sqlite::memory:']]], "state.driver 'pdo'"],
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('unavailable')]
    public function testDriversArrivingLaterAreRejectedByName(array $patch, string $expected): void
    {
        try {
            Watcher::fromConfig(array_replace(self::valid(), $patch));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString($expected, $e->getMessage());
            self::assertStringContainsString('not available yet', $e->getMessage());
        }
    }

    public function testInvalidConfigThrowsBeforeAnyBuild(): void
    {
        $this->expectException(InvalidConfig::class);
        Watcher::fromConfig(['service' => 'svc']);
    }
}
