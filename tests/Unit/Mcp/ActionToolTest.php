<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\Tools\ActionTool;
use Watcher\Mcp\ToolError;

final class ActionToolTest extends TestCase
{
    private int $clock = 1000;

    /** @var list<string> */
    private array $audited = [];

    private function tool(\Closure $run, int $interval = 300): ActionTool
    {
        return new ActionTool(
            name: 'watcher_test_action',
            description: 'test',
            inputSchema: ['type' => 'object', 'additionalProperties' => false, 'properties' => ['confirm' => ['type' => 'boolean']]],
            run: $run,
            audit: function (string $tool, array $data): void {
                $this->audited[] = $tool . ':' . ($data['exit'] ?? '?');
            },
            needsConfirm: static fn (array $args): bool => true,
            minIntervalSeconds: $interval,
            now: fn (): int => $this->clock,
        );
    }

    public function testRefusesWithoutConfirm(): void
    {
        $tool = $this->tool(static fn (array $args): array => ['exit' => 0]);

        try {
            $tool->call([]);
            self::fail('expected confirm_required');
        } catch (ToolError $e) {
            self::assertSame('confirm_required', $e->errorCode);
        }
        self::assertSame([], $this->audited);
    }

    public function testASecondCallInsideTheIntervalIsRateLimited(): void
    {
        $tool = $this->tool(static fn (array $args): array => ['exit' => 0]);
        $tool->call(['confirm' => true]);

        $this->clock += 60;

        try {
            $tool->call(['confirm' => true]);
            self::fail('expected rate_limited');
        } catch (ToolError $e) {
            self::assertSame('rate_limited', $e->errorCode);
            self::assertSame(240, $e->extra['retry_after']);
        }
    }

    public function testRunsAgainAfterTheIntervalAndAuditsEachRun(): void
    {
        $tool = $this->tool(static fn (array $args): array => ['exit' => 0, 'ran' => true]);
        $first = $tool->call(['confirm' => true]);

        $this->clock += 300;
        $second = $tool->call(['confirm' => true]);

        self::assertSame(['exit' => 0, 'ran' => true], $second);
        self::assertSame(['exit' => 0, 'ran' => true], $first);
        self::assertSame(['watcher_test_action:0', 'watcher_test_action:0'], $this->audited);
    }

    public function testNoConfirmNeededWhenTheToolSaysSo(): void
    {
        $tool = new ActionTool(
            name: 'watcher_test_action',
            description: 'test',
            inputSchema: ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
            run: static fn (array $args): array => ['exit' => 0],
            audit: static function (): void {
            },
            needsConfirm: static fn (array $args): bool => false,
            minIntervalSeconds: 300,
            now: fn (): int => $this->clock,
        );

        self::assertSame(['exit' => 0], $tool->call([]));
    }
}
