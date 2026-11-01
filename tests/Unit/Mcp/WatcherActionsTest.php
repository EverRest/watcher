<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\Tools\WatcherActions;

final class WatcherActionsTest extends TestCase
{
    public function testDigestIsAFleetDryRunAsJsonByDefault(): void
    {
        [$command, $flags] = WatcherActions::invocation('watcher_run_digest', []);

        self::assertSame('watcher:fleet', $command);
        self::assertSame(['--dry-run', '--json'], $flags);
    }

    public function testARealDigestNeedsDryRunFalse(): void
    {
        [, $flags] = WatcherActions::invocation('watcher_run_digest', ['dry_run' => false]);

        self::assertSame(['--json'], $flags);
    }

    public function testASingleServiceUsesTheRunCommand(): void
    {
        [$command, $flags] = WatcherActions::invocation('watcher_run_digest', ['fleet' => false, 'dry_run' => false]);

        self::assertSame('watcher:run', $command);
        self::assertSame(['--json'], $flags);
    }

    public function testNowAndOnlyBecomeFlags(): void
    {
        [, $flags] = WatcherActions::invocation('watcher_run_digest', ['now' => '2026-11-02T06:00:00Z', 'only' => 'm-seq,m-seq-schedule']);

        self::assertContains('--now=2026-11-02T06:00:00Z', $flags);
        self::assertContains('--only=m-seq,m-seq-schedule', $flags);
    }

    public function testNowIsRefusedForARealRun(): void
    {
        $this->expectException(\Watcher\Mcp\ToolError::class);
        $this->expectExceptionMessage('now_needs_dry_run');

        WatcherActions::invocation('watcher_run_digest', ['now' => '2026-11-02T06:00:00Z', 'dry_run' => false]);
    }

    public function testOnlyIsIgnoredForASingleService(): void
    {
        [$command, $flags] = WatcherActions::invocation('watcher_run_digest', ['fleet' => false, 'only' => 'm-seq']);

        self::assertSame('watcher:run', $command);
        self::assertNotContains('--only=m-seq', $flags);
    }

    public function testHealthCheckRunsTheHealthCommand(): void
    {
        self::assertSame(['watcher:health', []], WatcherActions::invocation('watcher_health_check', []));
    }

    public function testTestMessagePostsOneLabelledMessage(): void
    {
        self::assertSame(['watcher:check', ['--post-test', '--json']], WatcherActions::invocation('watcher_test_message', []));
    }

    public function testOnlyARealDigestAndTheActionsThatSendNeedConfirm(): void
    {
        self::assertFalse(WatcherActions::needsConfirm('watcher_run_digest', []));
        self::assertTrue(WatcherActions::needsConfirm('watcher_run_digest', ['dry_run' => false]));
        self::assertTrue(WatcherActions::needsConfirm('watcher_health_check', []));
        self::assertTrue(WatcherActions::needsConfirm('watcher_test_message', []));
    }
}
