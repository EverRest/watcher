<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

final class ScheduleConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function base(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => [
                'driver' => 'loki',
                'loki' => ['url' => 'https://loki', 'auth' => ['type' => 'bearer', 'token' => 'tok']],
            ],
            'analysis' => [
                'api_keys' => ['gemini' => 'sk'],
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/h']],
        ];
    }

    public function testEveryAndRunAtMutuallyExclusive(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('schedule.run_at and schedule.every are mutually exclusive');
        WatchConfig::fromArray(array_replace_recursive(self::base(), [
            'schedule' => ['run_at' => '06:00', 'every' => 'PT1H'],
        ]));
    }

    public function testEveryAllowedSet(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('schedule.every must be one of PT1H, PT2H, PT3H, PT4H, PT6H, PT8H, PT12H');
        WatchConfig::fromArray(array_replace_recursive(self::base(), [
            'schedule' => ['every' => 'PT30M'],
        ]));
    }

    public function testHourlyDefaultsGuards(): void
    {
        $c = WatchConfig::fromArray(array_replace_recursive(self::base(), [
            'schedule' => ['every' => 'PT1H', 'timezone' => 'UTC', 'run_at' => null],
        ]));
        self::assertSame(24, $c->schedule['runs_per_day']);
    }

    public function testRunAtListGap(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('schedule.run_at entries must be at least 1 hour apart');
        WatchConfig::fromArray(array_replace_recursive(self::base(), [
            'schedule' => ['run_at' => ['06:00', '06:30']],
        ]));
    }
}
