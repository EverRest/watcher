<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Fleet\FleetConfig;
use Watcher\Fleet\FleetStatus;
use Watcher\Level;
use Watcher\Operate\RunOptions;

/** Quickstart 28-30: services that cannot host the package are watched from one place, with a fleet heartbeat. */
final class FleetHostingTest extends TestCase
{
    /** @return list<\Watcher\Source\LogEvent> */
    private static function alpha(): array
    {
        return Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
    }

    public function testServicesWithoutPackage(): void
    {
        // two services that exist only as a log selection and an entry: no scheduler, no CLI, no code of their own
        $h = new FleetHarness(['legacy-a', 'legacy-b']);
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alpha());
        }
        $h->runner()->run(null, RunOptions::default());
        $h->nextWindow();
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, [...self::alpha(), ...Harness::events(Level::Error, 'Beta broke', 3, 'Beta')]);
        }

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertSame(0, $r->exitCode());
        foreach (['legacy-a', 'legacy-b'] as $n) {
            self::assertStringContainsString('🔎 ' . $n, implode("\n", FleetHarness::texts($h->notifiers[$n])), "$n has its digest");
        }
    }

    public function testFleetHeartbeat(): void
    {
        $h = new FleetHarness(['api', 'bff-a']);
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alpha());
        }
        $h->runner()->run(null, RunOptions::default());
        $config = FleetConfig::fromArray($h->fleetArray());

        $status = FleetStatus::forState($config, $h->state, $h->clock);

        self::assertSame(0, $status->exitCode());
        self::assertSame('ok', $status->fleet->state);
        self::assertSame(['api', 'bff-a'], array_keys($status->services));
        self::assertStringContainsString('fleet:          platform', $status->text());
        self::assertStringContainsString('service:        bff-a', $status->text());
        $json = json_decode($status->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['schema', 'fleet', 'exit', 'fleet_status', 'services'], array_keys($json));
        self::assertSame(0, $json['exit']);

        $h->clock->advance('+3 days'); // past the default heartbeat.stale_after
        $stale = FleetStatus::forState($config, $h->state, $h->clock);
        self::assertSame(1, $stale->exitCode());
        self::assertSame('stale', $stale->fleet->state);
    }

    public function testNothingRunYetIsUnknownNotHealthy(): void
    {
        $h = new FleetHarness(['api']);

        $status = FleetStatus::forState(FleetConfig::fromArray($h->fleetArray()), $h->state, $h->clock);

        self::assertSame(3, $status->exitCode());
    }

    public function testAFailedServiceMakesTheFleetStatusNonZero(): void
    {
        $h = new FleetHarness(['api', 'bff-a']);
        $h->logs('api', self::alpha());
        $h->logs('bff-a', self::alpha());
        $h->runner()->run(null, RunOptions::default());
        $h->nextWindow();
        $h->crash['api'] = new \RuntimeException('x');
        $h->runner()->run(null, RunOptions::default());

        $status = FleetStatus::forState(FleetConfig::fromArray($h->fleetArray()), $h->state, $h->clock);

        self::assertSame(1, $status->exitCode());
        self::assertSame('failed', $status->fleet->state);
    }

    public function testReleasePerService(): void
    {
        $h = new FleetHarness(['api', 'bff-a'], [], ['api' => ['deploy' => ['release' => 'api-v7']], 'bff-a' => ['deploy' => ['release' => 'bff-v3']]]);
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alpha());
        }
        $h->runner()->run(null, RunOptions::default());
        $h->nextWindow();
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, [...self::alpha(), ...Harness::events(Level::Error, 'Beta broke', 3, 'Beta')]);
        }

        $h->runner()->run(null, RunOptions::default());

        $api = implode("\n", FleetHarness::texts($h->notifiers['api']));
        $bff = implode("\n", FleetHarness::texts($h->notifiers['bff-a']));
        self::assertStringContainsString('api-v7', $api);
        self::assertStringNotContainsString('bff-v3', $api);
        self::assertStringContainsString('bff-v3', $bff);
        self::assertStringNotContainsString('api-v7', $bff);
    }
}
