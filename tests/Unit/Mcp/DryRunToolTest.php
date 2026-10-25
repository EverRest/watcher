<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\DryRunGuard;
use Watcher\Mcp\Tools\DryRunTool;
use Watcher\Mcp\ToolError;

final class DryRunToolTest extends TestCase
{
    private function tool(FakePipeline $p, float $spent = 0.0): DryRunTool
    {
        return new DryRunTool($p, new DryRunGuard(static fn (): array => ['usd' => $spent, 'tokens' => 0], 0.5, null), InspectFixtures::guard());
    }

    public function testEstimateOnlyNeedsNoConfirmationAndTouchesNoCounter(): void
    {
        $p = new FakePipeline();
        $t = $this->tool($p);
        $d = $t->call(['estimate_only' => true]);
        self::assertSame(0, $p->runs);
        self::assertSame(0.06, $d['total_usd']);
        self::assertSame(['step' => 'triage', 'groups' => 10, 'tokens_in' => 1000, 'tokens_out_max' => 500, 'cost_usd' => 0.01], $d['estimate'][0]);
        for ($i = 0; $i < 20; ++$i) {
            $t->call(['estimate_only' => true]);
        }
        self::assertSame(0, $p->runs);
    }

    public function testAConfirmedRunReturnsTheReportShapeWithTheBound(): void
    {
        $p = new FakePipeline();
        $d = $this->tool($p)->call(['confirm_cost' => true]);
        self::assertSame(1, $p->runs);
        self::assertTrue($d['dry_run']);
        self::assertEqualsWithDelta(0.10, $d['estimate_usd'], 1e-9);
        self::assertSame('reported', $d['outcome']);
        self::assertSame(['fp' => 'a3f9c01d22be', 'severity' => 'error', 'title' => 'Timeouts'], $d['findings'][0]);
        self::assertArrayNotHasKey('digest', $d);
    }

    public function testTheSecondCallIsRefusedWhenTheFirstConsumedTheBudget(): void
    {
        $p = new FakePipeline();
        $t = $this->tool($p, 0.30); // 0.20 left: one run of 0.10 fits twice, three times not
        $t->call(['confirm_cost' => true]);
        $t->call(['confirm_cost' => true]);
        try {
            $t->call(['confirm_cost' => true]);
            self::fail();
        } catch (ToolError $e) {
            self::assertSame('daily_limit_reached', $e->errorCode);
        }
        self::assertSame(2, $p->runs);
    }

    public function testRefusalsMakeNoModelCall(): void
    {
        $p = new FakePipeline();
        foreach ([[], ['confirm_cost' => false]] as $args) {
            try {
                $this->tool($p)->call($args);
            } catch (ToolError $e) {
                self::assertSame('cost_not_confirmed', $e->errorCode);
            }
        }
        self::assertSame(0, $p->runs);
    }

    public function testAFailingRunClearsBusyAndBooksNothing(): void
    {
        $p = new FakePipeline(onRun: static fn (): array => throw new \RuntimeException('boom'));
        $guard = new DryRunGuard(static fn (): array => ['usd' => 0.0, 'tokens' => 0], 0.5, null);
        $t = new DryRunTool($p, $guard, InspectFixtures::guard());
        try {
            $t->call(['confirm_cost' => true]);
            self::fail();
        } catch (ToolError $e) {
            self::assertSame('internal_error', $e->errorCode);
        }
        self::assertFalse($guard->busy());
        self::assertEqualsWithDelta(0.5, $guard->spendToday()['remaining_usd'], 1e-9);
    }

    public function testItIsAbsentUnlessAllowedAndInvalidParamsWhenCalled(): void
    {
        $tool = $this->tool(new FakePipeline());
        $h = new McpHarness();
        $r = $h->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], McpHarness::call('watcher_dry_run', ['confirm_cost' => true], 2)], [$tool], ['inspect'], false);
        self::assertSame([], $r[0]['result']['tools']);
        self::assertSame(-32602, $r[1]['error']['code']);
        $r = (new McpHarness())->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']], [$tool], ['inspect'], true);
        self::assertSame('watcher_dry_run', $r[0]['result']['tools'][0]['name']);
        self::assertTrue($r[0]['result']['tools'][0]['annotations']['openWorldHint']);
    }
}
