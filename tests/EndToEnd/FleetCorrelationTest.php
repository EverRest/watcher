<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\Operate\RunOptions;
use Watcher\Tests\Fakes\SpyLogger;

/** Quickstart 18-24, 26, 27: one additive correlated finding, annotated consumers, nothing lost, no model calls. */
final class FleetCorrelationTest extends TestCase
{
    private const API_DEP = ['dependencies' => ['api' => ['stream_tags' => ['app' => 'api', 'env' => 'stg']]]];

    /** @return list<\Watcher\Source\LogEvent> */
    private static function alpha(): array
    {
        return Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
    }

    /** @param array<string, mixed> $fleet */
    private static function harness(array $fleet = []): FleetHarness
    {
        return new FleetHarness(['api', 'bff-a', 'bff-b'], $fleet, ['bff-a' => self::API_DEP, 'bff-b' => self::API_DEP]);
    }

    /** Baseline all services, move a window, then: api has a new DB error; the BFFs got 500s from api. */
    private static function ready(FleetHarness $h, string $bffCall = 'GET http://api/v1/items returned 500', ?string $bffB = null): void
    {
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alpha());
        }
        $h->runner()->run(null, RunOptions::default());
        $h->nextWindow();
        $h->logs('api', [...self::alpha(), ...Harness::events(Level::Error, 'Database pool exhausted', 9, 'PoolError')]);
        $h->logs('bff-a', [...self::alpha(), ...Harness::events(Level::Error, $bffCall, 6, 'UpstreamError')]);
        $h->logs('bff-b', [...self::alpha(), ...Harness::events(Level::Error, $bffB ?? $bffCall, 4, 'UpstreamError')]);
        foreach ($h->notifiers as $n) {
            $n->sent = [];
        }
        $h->fleetNotifier->sent = [];
    }

    private static function all(FleetHarness $h, string $service): string
    {
        return implode("\n", FleetHarness::texts($h->notifiers[$service]));
    }

    public function testCorrelatedFindingAndAnnotatedConsumer(): void
    {
        $h = self::harness();
        self::ready($h);

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertCount(1, $r->correlated);
        self::assertTrue($r->correlatedPosted);
        self::assertSame(0, $r->exitCode());
        self::assertCount(1, $h->fleetNotifier->sent);
        $posted = FleetHarness::texts($h->fleetNotifier)[0];
        self::assertStringContainsString('Probable upstream cause: api', $posted);
        self::assertStringContainsString('(hypothesis)', $posted);
        self::assertSame('https://hooks.invalid/fleet', $h->fleetNotifier->sent[0]['to']->webhook());

        // the consumer's own digest still holds its finding, annotated with the origin's short fingerprint
        $fp = substr($r->correlated[0]->toArray()['origin']['fingerprint'], 0, 12);
        self::assertStringContainsString('Problem', self::all($h, 'bff-a'));
        self::assertStringContainsString("likely caused by the api finding $fp (hypothesis)", self::all($h, 'bff-a'));
        self::assertStringNotContainsString('likely caused by', self::all($h, 'api'));
    }

    public function testManyConsumersOneFinding(): void
    {
        $h = self::harness();
        self::ready($h);

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertCount(1, $r->correlated);
        self::assertSame(['bff-a', 'bff-b'], array_column($r->correlated[0]->toArray()['consumers'], 'service'));
        self::assertStringContainsString('likely caused by the api finding', self::all($h, 'bff-b'));
        self::assertCount(1, $h->fleetNotifier->sent, 'one message, not one per consumer');
    }

    public function testNoReferenceNoClaim(): void
    {
        $h = self::harness();
        self::ready($h, 'Something else broke');

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertSame([], $r->correlated);
        self::assertNull($r->correlatedPosted);
        self::assertSame([], $h->fleetNotifier->sent);
        self::assertStringContainsString('Problem', self::all($h, 'api'), 'the origin reports normally');
    }

    public function testOriginOutsideTheFleet(): void
    {
        $h = new FleetHarness(['api', 'bff-a'], [], ['bff-a' => ['dependencies' => ['svc-x' => ['stream_tags' => ['app' => 'svc-x']]]]]);
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alpha());
        }
        $h->runner()->run(null, RunOptions::default());
        $h->nextWindow();
        $h->logs('api', [...self::alpha(), ...Harness::events(Level::Error, 'Database pool exhausted', 9)]);
        $h->logs('bff-a', [...self::alpha(), ...Harness::events(Level::Error, 'GET http://svc-x/v1 returned 500', 6)]);
        $h->fleetNotifier->sent = [];

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertSame([], $r->correlated);
        self::assertSame([], $h->fleetNotifier->sent);
        self::assertStringNotContainsString('likely caused by', self::all($h, 'bff-a'));
    }

    public function testNoModelCalls(): void
    {
        $on = self::harness();
        self::ready($on);
        $on->analyzer->requests = $on->analyzer->triageRequests = [];
        $on->runner()->run(null, RunOptions::default());

        $off = self::harness(['correlation' => ['enabled' => false]]);
        self::ready($off);
        $off->analyzer->requests = $off->analyzer->triageRequests = [];
        $off->runner()->run(null, RunOptions::default());

        self::assertNotSame([], $on->analyzer->requests);
        self::assertCount(count($off->analyzer->requests), $on->analyzer->requests);
        self::assertCount(count($off->analyzer->triageRequests), $on->analyzer->triageRequests);
        self::assertSame([], $off->fleetNotifier->sent);
        self::assertStringNotContainsString('likely caused by', self::all($off, 'bff-a'));
    }

    public function testCorrelatedPostFailureLosesNothing(): void
    {
        $h = self::harness();
        $h->hostLog = $log = new SpyLogger();
        self::ready($h);
        $h->fleetNotifier->failWith(new DeliveryFailed('down'));

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertFalse($r->correlatedPosted);
        self::assertSame(1, $r->exitCode());
        self::assertCount(1, $r->correlated);
        foreach (['bff-a', 'bff-b'] as $n) {
            self::assertCount(1, $h->notifiers[$n]->sent, "$n digest still delivered");
            self::assertStringContainsString('likely caused by the api finding', self::all($h, $n));
            self::assertStringContainsString('Problem', self::all($h, $n));
        }
        self::assertCount(1, $h->notifiers['api']->sent);
        $warnings = array_filter($log->records, static fn (array $r): bool => str_contains($r[1], 'correlated finding not delivered'));
        self::assertNotSame([], $warnings);
    }

    public function testDryRunPrintsInsteadOfPosting(): void
    {
        $h = self::harness();
        self::ready($h);

        $r = $h->runner()->run(null, RunOptions::dryRun());

        self::assertCount(1, $r->correlated);
        self::assertNull($r->correlatedPosted);
        self::assertStringContainsString('== DRY RUN message to correlated finding ==', $h->printed);
        self::assertStringContainsString('Probable upstream cause', $h->printed);
        self::assertSame([], $h->fleetNotifier->sent);
        foreach ($h->notifiers as $n) {
            self::assertSame([], $n->sent);
        }
        self::assertSame(0.0, $r->fleetCostTodayUsd);
    }

    public function testDryRunPrintsTheCombinedDigestOnce(): void
    {
        $h = self::harness(['digest' => 'combined']);
        self::ready($h);

        $h->runner()->run(null, RunOptions::dryRun());

        self::assertSame(1, substr_count($h->printed, '== DRY RUN message to digest =='), 'one combined digest, not one per service');
        self::assertStringContainsString('fleet platform', $h->printed);
        self::assertSame([], $h->fleetNotifier->sent);
    }

    public function testCombinedModeStillAnnotatesTheHeldDigests(): void
    {
        $h = self::harness(['digest' => 'combined']);
        self::ready($h);

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertTrue($r->combinedPosted);
        self::assertTrue($r->correlatedPosted);
        $texts = FleetHarness::texts($h->fleetNotifier);
        self::assertCount(2, $texts, 'combined digest + the additive correlated message');
        self::assertStringContainsString('likely caused by the api finding', $texts[0]);
    }
}
