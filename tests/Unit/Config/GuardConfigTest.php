<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Fleet\FleetConfig;

/** Availability settings (A4-01, A4-02, A4-12, A4-13, A4-15). */
final class GuardConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function cfg(array $patch = []): array
    {
        return array_replace_recursive([
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
        ], $patch);
    }

    public function testRunDeadlineDefaultsToLockTtlMinusAMargin(): void
    {
        self::assertSame(810, WatchConfig::fromArray(self::cfg())->schedule['run_deadline']);
        self::assertSame(3240, WatchConfig::fromArray(self::cfg(['schedule' => ['lock_ttl' => 3600]]))->schedule['run_deadline']);
        self::assertSame(30, WatchConfig::fromArray(self::cfg(['schedule' => ['lock_ttl' => 60]]))->schedule['run_deadline']);
    }

    public function testRunDeadlineCanBeSetButNeverAboveTheLockTtl(): void
    {
        self::assertSame(120, WatchConfig::fromArray(self::cfg(['schedule' => ['run_deadline' => 120]]))->schedule['run_deadline']);
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('schedule.run_deadline must not be longer than schedule.lock_ttl');
        WatchConfig::fromArray(self::cfg(['schedule' => ['run_deadline' => 901]]));
    }

    public function testMemoryBudgetDefaultsTo32AndIsValidated(): void
    {
        self::assertSame(32, WatchConfig::fromArray(self::cfg())->grouping['memory_budget_mb']);
        self::assertSame(8, WatchConfig::fromArray(self::cfg(['grouping' => ['memory_budget_mb' => 8]]))->grouping['memory_budget_mb']);
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('grouping.memory_budget_mb must be an integer >= 1');
        WatchConfig::fromArray(self::cfg(['grouping' => ['memory_budget_mb' => 0]]));
    }

    public function testAnAtSignInTheServiceNameIsRejectedBecauseItJoinsServiceAndEnvironment(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('service must not contain @');
        WatchConfig::fromArray(self::cfg(['service' => 'a@b']));
    }

    public function testRedisReadTimeoutDefaultsToFiveSecondsAndIsConfigurable(): void
    {
        self::assertSame(5.0, Factory::redisReadTimeout([]));
        self::assertSame(2.5, Factory::redisReadTimeout(['read_timeout' => 2.5]));
        self::assertSame(5.0, Factory::redisReadTimeout(['read_timeout' => -1]));
    }

    public function testFleetServiceTimeBudgetDefaultsToAShareOfTheFleetBudget(): void
    {
        $svc = static fn (string $n): array => self::cfg(['service' => $n]);
        $fleet = FleetConfig::fromArray(['fleet' => 'f', 'time_budget' => 'PT30M', 'services' => [$svc('a'), $svc('b'), $svc('c')]]);
        self::assertSame(600, $fleet->serviceTimeBudgetSeconds());

        $set = FleetConfig::fromArray(['fleet' => 'f', 'time_budget' => 'PT30M', 'service_time_budget' => 'PT5M', 'services' => [$svc('a'), $svc('b')]]);
        self::assertSame(300, $set->serviceTimeBudgetSeconds());

        $none = FleetConfig::fromArray(['fleet' => 'f', 'services' => [$svc('a')]]);
        self::assertNull($none->serviceTimeBudgetSeconds());
    }
}
