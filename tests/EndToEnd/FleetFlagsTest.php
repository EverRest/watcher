<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Fleet\FleetConfig;
use Watcher\Level;
use Watcher\Operate\RunOptions;
use Watcher\Tests\Fakes\RecordingStateStore;

/** Spec 012 in a fleet: per-service and fleet `enabled`, and service@environment identity. */
final class FleetFlagsTest extends TestCase
{
    public function testADisabledServiceIsSkippedNotFailedAndTouchesNothing(): void
    {
        $h = new FleetHarness(servicePatches: ['bff-a' => ['enabled' => false]]);
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha'));
        }

        $r = $h->runner()->run(null, RunOptions::default());

        $outcomes = array_column(array_map(static fn ($s): array => ['s' => $s->service, 'o' => $s->outcome], $r->services), 'o', 's');
        self::assertSame('disabled', $outcomes['bff-a']);
        self::assertSame('baselined', $outcomes['api']);
        self::assertSame(0, $r->exitCode());
        self::assertSame([], $h->sources['bff-a']->calls);
        self::assertSame(0, $h->notifiers['bff-a']->attempts);
        foreach (array_keys($h->state->all()) as $key) {
            self::assertStringNotContainsString('bff-a', $key);
        }
        self::assertStringContainsString('disabled=1', implode("\n", $r->lines()));
        self::assertSame('disabled', json_decode($r->toJson(), true)['services'][1]['outcome']);
    }

    public function testWithNoDisabledServiceTheFleetLineIsUnchanged(): void
    {
        $h = new FleetHarness();
        $r = $h->runner()->run(null, RunOptions::default());

        self::assertStringNotContainsString('disabled', implode("\n", $r->lines()));
    }

    public function testADisabledFleetReachesNoStoreSourceOrNotifier(): void
    {
        $h = new FleetHarness(fleetPatch: ['enabled' => false]);
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha'));
        }
        $rec = new RecordingStateStore($h->state);

        $r = $h->runner($rec)->run(null, RunOptions::default());

        self::assertSame(['disabled', 'disabled', 'disabled'], array_map(static fn ($s): string => $s->outcome, $r->services));
        self::assertSame(0, $r->exitCode());
        self::assertSame([], $rec->calls);
        self::assertSame([], $h->state->all());
        self::assertSame(0, $h->fleetNotifier->attempts);
        foreach ($h->sources as $s) {
            self::assertSame([], $s->calls);
        }
    }

    public function testFleetEnabledMustBeABoolean(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('enabled must be true or false');
        FleetConfig::fromArray((new FleetHarness(fleetPatch: ['enabled' => 'false']))->fleetArray());
    }

    public function testTheSameServiceInTwoEnvironmentsIsNotADuplicate(): void
    {
        $h = new FleetHarness(['api', 'api2'], servicePatches: ['api' => ['environment' => 'int'], 'api2' => ['service' => 'api', 'environment' => 'prod']]);

        $f = FleetConfig::fromArray($h->fleetArray());

        self::assertSame(['api@int', 'api@prod'], array_map(static fn ($e): string => $e->name(), $f->services));
    }

    public function testTheSameServiceAndEnvironmentTwiceIsRejected(): void
    {
        $h = new FleetHarness(['api', 'api2'], servicePatches: ['api' => ['environment' => 'int'], 'api2' => ['service' => 'api', 'environment' => 'int']]);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('duplicate service');
        FleetConfig::fromArray($h->fleetArray());
    }

    public function testEnabledAndEnvironmentCanComeFromFleetDefaults(): void
    {
        $h = new FleetHarness(fleetPatch: ['defaults' => ['enabled' => false, 'environment' => 'dev']]);
        $f = FleetConfig::fromArray($h->fleetArray());

        // the entries set enabled themselves in the harness, so defaults lose; environment is inherited
        self::assertSame('api@dev', $f->services[0]->name());
        self::assertTrue($f->services[0]->config->enabled);
    }
}
