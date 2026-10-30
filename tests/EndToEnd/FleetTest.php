<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Level;
use Watcher\Operate\RunOptions;
use Watcher\Source\SourceUnavailable;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FailingStateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/** Quickstart 1-5, 8, 9, 14: the fleet loop, isolation, order of results, exit code. */
final class FleetTest extends TestCase
{
    /** @return list<\Watcher\Source\LogEvent> */
    private static function alpha(int $n = 5): array
    {
        return Harness::events(Level::Error, 'Alpha failed in a unique way', $n, 'Alpha');
    }

    /**
     * Warning only: a first run with an Error group now reports (FR-035), so baseline setups use this.
     *
     * @return list<\Watcher\Source\LogEvent>
     */
    private static function alphaWarning(int $n = 5): array
    {
        return Harness::events(Level::Warning, 'Alpha failed in a unique way', $n, 'Alpha');
    }

    /** @param list<\Watcher\Source\LogEvent> $e */
    private static function source(array $e): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf($e), $e);
    }

    /** Baseline window for every service, then move to the next so the following run reports. */
    private static function baselined(FleetHarness $h): void
    {
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alpha());
        }
        $h->runner()->run(null, RunOptions::default());
        $h->nextWindow();
    }

    /** @return array<string, string> service => outcome */
    private static function outcomes(\Watcher\Fleet\FleetResult $r): array
    {
        $o = [];
        foreach ($r->services as $s) {
            $o[$s->service] = $s->outcome;
        }

        return $o;
    }

    public function testEqualsThreeSingleRuns(): void
    {
        $h = new FleetHarness();
        self::baselined($h);
        $withBeta = [...self::alpha(), ...Harness::events(Level::Error, 'Beta broke', 3, 'Beta')];
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, $withBeta);
        }
        $result = $h->runner()->run(null, RunOptions::default());

        self::assertSame(['api' => 'reported', 'bff-a' => 'reported', 'bff-b' => 'reported'], self::outcomes($result));
        self::assertSame(0, $result->exitCode());
        $windowEnd = Watcher::windowKey($h->clock->now());
        foreach (['api', 'bff-a', 'bff-b'] as $n) {
            self::assertNotNull($h->state->get(Keys::run($n, $windowEnd)), "$n run record");
            self::assertNotNull($h->state->get(Keys::lastSnapshot($n)), "$n snapshot");
            self::assertCount(2, $h->notifiers[$n]->sent, "$n baseline + digest");
        }

        // the digest of each service equals the digest of the same service run alone over the same two windows
        foreach (['api', 'bff-a', 'bff-b'] as $n) {
            $alone = new FleetHarness([$n]);
            self::baselined($alone);
            $alone->logs($n, $withBeta);
            $alone->runner()->run(null, RunOptions::default());
            self::assertSame(FleetHarness::texts($alone->notifiers[$n]), FleetHarness::texts($h->notifiers[$n]), "$n equals a run alone");
        }
    }

    public function testFleetOfOneEqualsSingle(): void
    {
        $fleet = new FleetHarness(['api']);
        $fleet->logs('api', self::alpha());
        $fleetResult = $fleet->runner()->run(null, RunOptions::default());

        [$single, $notifier] = $fleet->single('api', self::source(self::alpha()));

        self::assertSame($single->outcome->value, $fleetResult->services[0]->outcome);
        self::assertSame($single->exitCode(), $fleetResult->exitCode());
        self::assertSame(FleetHarness::texts($notifier), FleetHarness::texts($fleet->notifiers['api']));
        $fleet->nextWindow();
        $fleet->logs('api', [...self::alpha(), ...Harness::events(Level::Error, 'Beta broke', 3, 'Beta')]);
        $second = $fleet->runner()->run(null, RunOptions::default());
        self::assertSame('reported', $second->services[0]->outcome);
    }

    public function testFailureIsIsolated(): void
    {
        $h = new FleetHarness();
        self::baselined($h);
        $h->sources['bff-a']->failWith(new SourceUnavailable('loki 401 Unauthorized'));
        foreach (['api', 'bff-b'] as $n) {
            $h->logs($n, [...self::alpha(), ...Harness::events(Level::Error, 'Beta broke', 3, 'Beta')]);
        }
        $sentA = count($h->notifiers['bff-a']->sent);

        $result = $h->runner()->run(null, RunOptions::default());

        self::assertSame(['api' => 'reported', 'bff-a' => 'failed', 'bff-b' => 'reported'], self::outcomes($result));
        self::assertSame(1, $result->exitCode());
        self::assertCount($sentA + 1, $h->notifiers['bff-a']->sent);
        self::assertStringContainsString('bff-a', FleetHarness::texts($h->notifiers['bff-a'])[$sentA]);
        self::assertCount(2, $h->notifiers['api']->sent);
        self::assertCount(2, $h->notifiers['bff-b']->sent);
    }

    public function testUnexpectedThrowIsContained(): void
    {
        $h = new FleetHarness();
        $h->crash['bff-a'] = new \RuntimeException('boom with secret hunter2');
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alphaWarning());
        }

        $result = $h->runner()->run(null, RunOptions::default());

        self::assertSame(['api' => 'baselined', 'bff-a' => 'failed', 'bff-b' => 'baselined'], self::outcomes($result));
        self::assertSame('internal error (RuntimeException)', $result->services[1]->reason);
        self::assertStringNotContainsString('hunter2', $result->toJson());
        self::assertSame(1, $result->exitCode());
    }

    public function testEachServiceKeepsItsOwnHour(): void
    {
        $h = new FleetHarness(['api', 'bff-a'], [], ['bff-a' => ['schedule' => ['run_at' => '07:00']]]);
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alphaWarning());
        }

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertSame(['api' => 'baselined', 'bff-a' => 'skipped_not_due'], self::outcomes($r));
        self::assertSame(0, $r->exitCode());
        self::assertSame([], $h->sources['bff-a']->calls);
    }

    public function testSecondTriggerSkipsAll(): void
    {
        $h = new FleetHarness();
        self::baselined($h);
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, [...self::alpha(), ...Harness::events(Level::Error, 'Beta broke', 3, 'Beta')]);
        }
        $first = $h->runner()->run(null, RunOptions::default());
        $posted = array_map(static fn ($n): int => count($n->sent), $h->notifiers);

        $second = $h->runner()->run(null, RunOptions::default());

        self::assertSame(['api' => 'reported', 'bff-a' => 'reported', 'bff-b' => 'reported'], self::outcomes($first));
        self::assertSame(['api' => 'skipped_done', 'bff-a' => 'skipped_done', 'bff-b' => 'skipped_done'], self::outcomes($second));
        self::assertSame($posted, array_map(static fn ($n): int => count($n->sent), $h->notifiers));
        self::assertSame(0, $second->exitCode());
    }

    public function testTimeBudgetSkipsAreExplicit(): void
    {
        $h = new FleetHarness(fleetPatch: ['time_budget' => 'PT1S']);
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alphaWarning());
        }
        $h->afterService = function (string $n) use ($h): void {
            $h->clock->advance('+5 seconds');
        };

        $r = $h->runner()->run(null, RunOptions::default());

        self::assertSame(['api' => 'baselined', 'bff-a' => 'skipped_time', 'bff-b' => 'skipped_time'], self::outcomes($r));
        self::assertSame(1, $r->exitCode());
        self::assertSame([], $h->sources['bff-a']->calls);
        self::assertStringContainsString('bff-a: outcome=skipped_time', implode("\n", $r->lines()));
    }

    public function testDigestDestinations(): void
    {
        $h = new FleetHarness();
        self::baselined($h);

        $webhooks = array_map(static fn ($n): string => $n->sent[0]['to']->webhook(), $h->notifiers);

        self::assertSame(['api' => 'https://hooks.invalid/api', 'bff-a' => 'https://hooks.invalid/bff-a', 'bff-b' => 'https://hooks.invalid/fleet'], $webhooks);
    }

    public function testOnlyRunsASubsetAndRejectsUnknownNames(): void
    {
        $h = new FleetHarness();
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alphaWarning());
        }

        $r = $h->runner()->run(null, RunOptions::default(), ['bff-b']);

        self::assertSame(['bff-b' => 'baselined'], self::outcomes($r));
        self::assertSame([], $h->sources['api']->calls);

        $this->expectException(InvalidConfig::class);
        $h->runner()->run(null, RunOptions::default(), ['nope']);
    }

    public function testFleetHeartbeatIsRecordedAndNeverChangesTheResult(): void
    {
        $h = new FleetHarness();
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alphaWarning());
        }
        $h->runner()->run(null, RunOptions::default());
        $hb = $h->state->get(Keys::heartbeat('fleet/platform'));
        self::assertNotNull($hb);
        self::assertSame('quiet', $hb['outcome']); // a baseline counts as a healthy quiet run

        $failing = new FailingStateStore($h->state);
        $failing->failOn('put');
        $h->nextWindow();
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alpha());
        }
        $r = $h->runner($failing)->run(null, RunOptions::default());

        self::assertSame(0, $r->exitCode());
        self::assertSame(['api' => 'reported', 'bff-a' => 'reported', 'bff-b' => 'reported'], self::outcomes($r));
    }

    public function testDryRunWritesNoFleetHeartbeat(): void
    {
        $h = new FleetHarness(['api']);
        $h->logs('api', self::alpha());

        $h->runner()->run(null, RunOptions::dryRun());

        self::assertNull($h->state->get(Keys::heartbeat('fleet/platform')));
        self::assertSame([], $h->notifiers['api']->sent);
    }

    public function testFailedServiceMakesTheFleetHeartbeatFailed(): void
    {
        $h = new FleetHarness(['api']);
        $h->crash['api'] = new \LogicException('x');
        $h->runner()->run(null, RunOptions::default());

        $hb = $h->state->get(Keys::heartbeat('fleet/platform'));
        self::assertSame('failed', $hb['outcome'] ?? null);
        self::assertSame('1 service failed', $hb['reason'] ?? null);
    }
}
