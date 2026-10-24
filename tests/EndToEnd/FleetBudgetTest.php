<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\Operate\RunOptions;
use Watcher\Source\LogEvent;

/** Quickstart 11, 12, 13, 15: the shared budget and the combined digest. */
final class FleetBudgetTest extends TestCase
{
    /** The analyze model costs $5 per million input tokens (the EchoAnalyzer is set to bill 2400 in): $0.012 per analysis (2400 tokens); triage is free. */
    private const PRICES = ['analysis' => ['prices' => ['m-t' => [0, 0, 0, 0], 'm-a' => [5, 0, 0, 0], 'm-e' => [0, 0, 0, 0]]]];

    /** @return list<LogEvent> */
    private static function alpha(): array
    {
        return Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
    }

    /** @return list<LogEvent> */
    private static function withBeta(): array
    {
        return [...self::alpha(), ...Harness::events(Level::Error, 'Beta broke', 3, 'Beta')];
    }

    /** @param array<string, mixed> $fleet */
    private static function harness(array $fleet = []): FleetHarness
    {
        $h = new FleetHarness(['api', 'bff-a', 'bff-b'], $fleet, ['api' => self::PRICES, 'bff-a' => self::PRICES, 'bff-b' => self::PRICES]);
        $h->analyzer->inputTokens = 2400;

        return $h;
    }

    /**
     * Baseline every service, move on a day, then give the listed ones a new group (the others log nothing).
     *
     * @param list<string> $withBeta
     */
    private static function ready(FleetHarness $h, array $withBeta = ['api', 'bff-a', 'bff-b']): void
    {
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, self::alpha());
        }
        $h->runner()->run(null, RunOptions::default());
        $h->nextWindow();
        foreach (array_keys($h->sources) as $n) {
            $h->logs($n, in_array($n, $withBeta, true) ? self::withBeta() : []);
        }
        $h->fleetNotifier->sent = [];
    }

    private static function all(FleetHarness $h, string $service): string
    {
        return implode("\n", FleetHarness::texts($h->notifiers[$service]));
    }

    public function testFleetCapStopsLaterServices(): void
    {
        // Security review 5 (F5): a step now reserves BOTH attempts, so each analysis is booked at 4800 input tokens ($0.024) and the cap is
        // two of them: api and bff-a still fit their reservation, and after bff-a the cap is spent.
        $h = self::harness(['budget' => ['daily_budget_usd' => 0.048]]);
        $h->analyzer->inputTokens = 4800;
        self::ready($h);
        $h->analyzer->requests = [];
        $h->analyzer->triageRequests = [];

        $result = $h->runner()->run(null, RunOptions::default());

        self::assertCount(2, $h->analyzer->requests, 'api and bff-a analysed, bff-b not');
        self::assertCount(3, $h->analyzer->triageRequests, 'every service still triaged');
        self::assertStringContainsString('budget exhausted (fleet cap)', self::all($h, 'bff-b'));
        self::assertStringNotContainsString('budget exhausted', self::all($h, 'bff-a'));
        self::assertStringNotContainsString('budget exhausted', self::all($h, 'api'));
        self::assertEqualsWithDelta(0.048, $result->fleetCostTodayUsd, 1e-9);
        self::assertStringContainsString('"fleet_cost_today_usd":0.048', $result->toJson());
    }

    public function testServiceCapOnly(): void
    {
        $h = self::harness(['budget' => ['daily_budget_usd' => 100.0]]);
        self::ready($h);
        $h->analyzer->requests = [];
        (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'bff-a'))->add(new Usage('m', 1, 1), 5.0);

        $h->runner()->run(null, RunOptions::default());

        self::assertStringContainsString('budget exhausted (service cap)', self::all($h, 'bff-a'));
        self::assertStringNotContainsString('budget exhausted', self::all($h, 'api'));
        self::assertStringNotContainsString('budget exhausted', self::all($h, 'bff-b'));
        self::assertCount(2, $h->analyzer->requests, 'api and bff-b analysed');
    }

    public function testFleetThresholdsOncePerDay(): void
    {
        // Two attempts are reserved per step (F5): each analysis is booked at 3600 input tokens ($0.018) and the cap is raised so all three still run.
        $h = self::harness(['budget' => ['daily_budget_usd' => 0.057]]);
        $h->analyzer->inputTokens = 3600;
        self::ready($h);

        $h->runner()->run(null, RunOptions::default());

        $texts = FleetHarness::texts($h->fleetNotifier);
        self::assertCount(3, $texts, '30%, 60% and 90%: one notice each, the highest newly crossed');
        self::assertStringContainsString('budget 25%', $texts[0]);
        self::assertStringContainsString('budget 50%', $texts[1]);
        self::assertStringContainsString('budget 90%', $texts[2]);
        self::assertStringContainsString('fleet platform', $texts[0]);

        $h->runner()->run(null, RunOptions::default()); // same day, same window: every service is done
        self::assertCount(3, $h->fleetNotifier->sent);
    }

    public function testAFailedFleetAlertNeverFailsTheRun(): void
    {
        $h = self::harness(['budget' => ['daily_budget_usd' => 0.04]]);
        self::ready($h, ['api']);
        $h->fleetNotifier->failOnce(new DeliveryFailed('down'));

        $result = $h->runner()->run(null, RunOptions::default());

        self::assertSame(1, $h->fleetNotifier->attempts);
        self::assertSame([], $h->fleetNotifier->sent);
        self::assertSame(0, $result->exitCode());
    }

    public function testCombinedMode(): void
    {
        $h = self::harness(['digest' => 'combined']);
        self::ready($h, ['api', 'bff-a']);

        $result = $h->runner()->run(null, RunOptions::default());

        self::assertCount(1, $h->fleetNotifier->sent, 'exactly one combined message');
        $sent = $h->fleetNotifier->sent[0];
        self::assertSame('https://hooks.invalid/fleet', $sent['to']->webhook());
        $texts = array_column($sent['message']->sections, 'text');
        self::assertContains('*api*', $texts);
        self::assertContains('*bff-a*', $texts);
        self::assertNotContains('*bff-b*', $texts);
        self::assertStringContainsString('bff-b', implode("\n", $texts), 'the quiet service is one line');
        foreach (['api', 'bff-a', 'bff-b'] as $n) {
            self::assertCount(1, $h->notifiers[$n]->sent, "$n: only the baseline notice, no digest of its own");
        }
        self::assertTrue($result->combinedPosted);
        self::assertSame(0, $result->exitCode());
        self::assertSame(['reported', 'reported', 'quiet'], array_map(static fn ($s): string => $s->outcome, $result->services));
    }

    public function testCombinedModeNothingReportedPostsNothing(): void
    {
        $h = self::harness(['digest' => 'combined']);
        self::ready($h, []);

        $result = $h->runner()->run(null, RunOptions::default());

        self::assertSame([], $h->fleetNotifier->sent);
        self::assertNull($result->combinedPosted);
    }

    public function testCombinedPostFailureIsLoudAndLosesNoRecord(): void
    {
        $h = self::harness(['digest' => 'combined']);
        self::ready($h, ['api']);
        $h->fleetNotifier->failWith(new DeliveryFailed('down'));

        $result = $h->runner()->run(null, RunOptions::default());

        self::assertFalse($result->combinedPosted);
        self::assertSame(1, $result->exitCode());
        self::assertSame('reported', $result->services[0]->outcome);
    }

    public function testDryRunBooksNothingAndPostsNothing(): void
    {
        $h = self::harness(['budget' => ['daily_budget_usd' => 0.04]]);
        self::ready($h);

        $result = $h->runner()->run(null, RunOptions::dryRun());

        self::assertGreaterThan(0.0, $result->fleetCostTodayUsd, 'the dry run shows its own cumulative cost');
        self::assertSame(0.0, (new Spend($h->state, $h->clock, new DateTimeZone('UTC'), 'fleet/platform'))->today()['usd'], 'nothing reached the real counter');
        self::assertSame([], $h->fleetNotifier->sent);
    }

    public function testFleetPostsUseTheFleetDestinationOfAnyDriver(): void
    {
        $hook = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-123';
        $h = self::harness(['digest' => 'combined', 'budget' => ['daily_budget_usd' => 0.04], 'notify' => ['digest' => ['driver' => 'discord', 'webhook' => $hook]]]);
        self::ready($h, ['api', 'bff-a']);

        $h->runner()->run(null, RunOptions::default());

        self::assertGreaterThan(1, count($h->fleetNotifier->sent), 'budget alerts and the combined digest');
        foreach ($h->fleetNotifier->sent as $sent) {
            self::assertSame('discord', $sent['to']->driver());
            self::assertSame($hook, $sent['to']->webhook());
        }
    }
}
