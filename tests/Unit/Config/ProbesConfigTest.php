<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

/** The `probes` section and `schedule.probe_every`: absent is null, defaults are PT1H and 30/7 days, errors name key paths only. */
final class ProbesConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $probes
     * @param array<string, mixed> $schedule
     *
     * @return array<string, mixed>
     */
    private static function config(array $probes = [], array $schedule = []): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'] + $schedule,
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3']],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/SECRET']],
        ] + ($probes === [] ? [] : ['probes' => $probes]);
    }

    public function testAbsentSectionIsNullAndProbeEveryDefaultsToAnHour(): void
    {
        $c = WatchConfig::fromArray(self::config());

        self::assertNull($c->probes);
        self::assertSame(3600, $c->schedule['probe_every']);
    }

    public function testTheSectionDefaultsToOffWithThresholdsAndSecrets(): void
    {
        $probes = WatchConfig::fromArray(self::config(['hosts' => ['api.example.com'], 'secrets' => [['name' => 'DB_PASSWORD', 'expires' => '2027-01-31', 'owner' => 'team-orders']]]))->probes;

        self::assertNotNull($probes);
        self::assertFalse($probes->enabled);
        self::assertSame([30, 7], [$probes->warnDays, $probes->failDays]);
        self::assertSame(['api.example.com'], $probes->hosts);
        self::assertSame([['name' => 'DB_PASSWORD', 'expires' => '2027-01-31', 'owner' => 'team-orders']], $probes->secrets);
    }

    public function testProbeEveryIsReadFromTheSchedule(): void
    {
        self::assertSame(1800, WatchConfig::fromArray(self::config([], ['probe_every' => 'PT30M']))->schedule['probe_every']);
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>, string}> */
    public static function invalid(): array
    {
        return [
            'probe_every too short' => [[], ['probe_every' => 'PT1M'], 'schedule.probe_every'],
            'probe_every not a duration' => [[], ['probe_every' => 'hourly'], 'schedule.probe_every'],
            'unknown key' => [['bogus' => 1], [], 'probes.bogus'],
            'fail not below warn' => [['warn_days' => 5, 'fail_days' => 7], [], 'probes.fail_days'],
            'host with a slash' => [['hosts' => ['api.example.com/x']], [], 'probes.hosts'],
            'secret without owner' => [['secrets' => [['name' => 'K', 'expires' => '2027-01-01']]], [], 'probes.secrets'],
            'secret with a bad date' => [['secrets' => [['name' => 'K', 'expires' => '2027-02-30', 'owner' => 'o']]], [], 'probes.secrets[0].expires'],
            'secret with an extra key' => [['secrets' => [['name' => 'K', 'expires' => '2027-01-01', 'owner' => 'o', 'value' => 'x']]], [], 'probes.secrets[0]'],
            'enabled as a string' => [['enabled' => 'true'], [], 'probes.enabled'],
        ];
    }

    /**
     * @param array<string, mixed> $probes
     * @param array<string, mixed> $schedule
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefusedWithAKeyPathOnly(array $probes, array $schedule, string $path): void
    {
        try {
            WatchConfig::fromArray(self::config($probes, $schedule));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString($path, $e->getMessage());
            self::assertStringNotContainsString('sk-secret-key', $e->getMessage());
        }
    }
}
