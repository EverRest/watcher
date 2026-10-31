<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Health\HealthCheck;

final class HealthCheckConfigTest extends TestCase
{
    public function testOffByDefaultWithoutTouchingAnything(): void
    {
        self::assertNull(HealthCheck::fromConfig(['enabled' => true]));
        self::assertNull(HealthCheck::fromConfig(['enabled' => true, 'health_check' => ['enabled' => false]]));
    }

    public function testTheWatcherMasterSwitchTurnsItOffToo(): void
    {
        self::assertNull(HealthCheck::fromConfig(['enabled' => false, 'health_check' => ['enabled' => true]]));
    }

    public function testEnabledMustBeABoolean(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('health_check.enabled must be true or false');

        HealthCheck::fromConfig(['enabled' => true, 'health_check' => ['enabled' => 'yes']]);
    }

    public function testTheSourceNeedsAHostAndAKey(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('health_check.source.host and health_check.source.key are required');

        HealthCheck::fromConfig(['enabled' => true, 'health_check' => ['enabled' => true, 'source' => ['key' => 'm-seq:health']]]);
    }

    /** @dataProvider badTtlDays */
    public function testTtlDaysIsOneToAYear(mixed $days): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('health_check.ttl_days must be 1..365');

        HealthCheck::fromConfig(['enabled' => true, 'health_check' => [
            'enabled' => true,
            'source' => ['host' => 'ew-redis', 'key' => 'm-seq:health'],
            'ttl_days' => $days,
        ]]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badTtlDays(): iterable
    {
        yield 'zero' => [0];
        yield 'too long' => [366];
        yield 'a string' => ['14'];
    }

    /** @dataProvider badSummaryAt */
    public function testSummaryAtIsHhMmOrNull(mixed $value): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('health_check.summary_at must be HH:MM (UTC), hourly or null');

        HealthCheck::fromConfig(['enabled' => true, 'health_check' => [
            'enabled' => true,
            'source' => ['host' => 'ew-redis', 'key' => 'm-seq:health'],
            'summary_at' => $value,
        ]]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badSummaryAt(): iterable
    {
        yield 'hour 24' => ['24:00'];
        yield 'minute 60' => ['09:60'];
        yield 'no colon' => ['0900'];
        yield 'one digit' => ['9:00'];
        yield 'an int' => [9];
        yield 'wrong case' => ['Hourly'];
    }

    /** A bare config fails later ("service is required"), so only the summary_at message is ruled out. */
    public function testSummaryAtAcceptsValidTimesAndNull(): void
    {
        foreach (['00:00', '09:00', '23:59', 'hourly', null] as $value) {
            try {
                HealthCheck::fromConfig(['enabled' => true, 'health_check' => [
                    'enabled' => true,
                    'source' => ['host' => 'ew-redis', 'key' => 'm-seq:health'],
                    'summary_at' => $value,
                ]]);
            } catch (InvalidConfig $e) {
                self::assertStringNotContainsString('summary_at', $e->getMessage());
            }
        }
    }
}
