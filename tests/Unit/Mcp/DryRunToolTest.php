<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\DryRunGuard;
use Watcher\Mcp\Tools\DryRunTool;
use Watcher\Mcp\ToolError;

final class DryRunToolTest extends TestCase
{
    private function tool(FakePipeline $p): DryRunTool
    {
        return new DryRunTool($p, new DryRunGuard(), InspectFixtures::guard());
    }

    public function testEstimateOnlyNeedsNoConfirmationAndMakesNoRun(): void
    {
        $p = new FakePipeline();
        $d = $this->tool($p)->call(['estimate_only' => true]);
        self::assertSame(['step' => 'triage', 'groups' => 10], $d['estimate'][0]);
        self::assertSame(0, $p->runs);
    }

    public function testAConfirmedRunReturnsTheReportShape(): void
    {
        $p = new FakePipeline();
        $d = $this->tool($p)->call(['confirm_model_calls' => true]);
        self::assertSame(1, $p->runs);
        self::assertTrue($d['dry_run']);
        self::assertSame('reported', $d['outcome']);
        self::assertSame(['fp' => 'a3f9c01d22be', 'severity' => 'error', 'title' => 'Timeouts'], $d['findings'][0]);
        self::assertArrayNotHasKey('digest', $d);
    }

    public function testAFailedRunRefusesARetryUntilRestart(): void
    {
        $p = new FakePipeline(onRun: static fn (): array => throw new \RuntimeException('provider down'));
        $t = $this->tool($p);
        try {
            $t->call(['confirm_model_calls' => true]);
            self::fail();
        } catch (ToolError $e) {
            self::assertSame('internal_error', $e->errorCode);
        }
        try {
            $t->call(['confirm_model_calls' => true]);
            self::fail();
        } catch (ToolError $e) {
            self::assertSame('previous_run_failed', $e->errorCode);
        }
        self::assertSame(1, $p->runs);
    }

    public function testRefusalsMakeNoModelCall(): void
    {
        $p = new FakePipeline();
        foreach ([[], ['confirm_model_calls' => false]] as $args) {
            try {
                $this->tool($p)->call($args);
                self::fail();
            } catch (ToolError $e) {
                self::assertSame('model_calls_not_confirmed', $e->errorCode);
            }
        }
        self::assertSame(0, $p->runs);
    }

    public function testItIsAbsentUnlessAllowedAndInvalidParamsWhenCalled(): void
    {
        $tool = $this->tool(new FakePipeline());
        $h = new McpHarness();
        $r = $h->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], McpHarness::call('watcher_dry_run', ['confirm_model_calls' => true], 2)], [$tool], ['inspect'], false);
        self::assertSame([], $r[0]['result']['tools']);
        self::assertSame(-32602, $r[1]['error']['code']);
        $r = (new McpHarness())->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']], [$tool], ['inspect'], true);
        self::assertSame('watcher_dry_run', $r[0]['result']['tools'][0]['name']);
        self::assertTrue($r[0]['result']['tools'][0]['annotations']['openWorldHint']);
    }
}
