<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\Tools\CheckTool;
use Watcher\Operate\CheckReport;
use Watcher\Operate\CheckResult;

final class CheckToolTest extends TestCase
{
    public function testTheSevenChecksInOrderWithVocabularyReasons(): void
    {
        $report = new CheckReport([
            CheckResult::pass('config'), CheckResult::pass('source'), CheckResult::pass('provider_auth', 'x-api-key accepted'), CheckResult::pass('models'), CheckResult::pass('prices'),
            CheckResult::pass('state', 'read-only probe'), CheckResult::fail('notifier', 'slack: https://hooks.example/T0/B0/xyz returned 500'),
            CheckResult::pass('observe', 'file'), CheckResult::pass('post_test'),
        ]);
        $d = (new CheckTool(static fn (): CheckReport => $report))->call([]);
        self::assertFalse($d['ok']);
        self::assertSame(['config', 'source', 'provider_auth', 'models', 'state', 'notifier'], array_column($d['checks'], 'name'));
        self::assertSame('read-only probe', $d['checks'][4]['reason']);
        self::assertSame('unavailable', $d['checks'][5]['reason']);
        self::assertNull($d['checks'][0]['reason']);
        self::assertStringNotContainsString('hooks.example', json_encode($d) ?: '');
    }

    public function testConfigFailureSkipsTheRest(): void
    {
        $report = \Watcher\Operate\Doctor::configFailure('invalid: analysis.models.triage');
        $d = (new CheckTool(static fn (): CheckReport => $report))->call([]);
        self::assertSame('fail', $d['checks'][0]['status']);
        self::assertSame('invalid: analysis.models.triage', $d['checks'][0]['reason']);
        self::assertSame(['skip'], array_values(array_unique(array_column(array_slice($d['checks'], 1), 'status'))));
    }

    public function testAnExceptionIsAnInternalErrorAndMessageNeverLeaks(): void
    {
        $tool = new CheckTool(static fn (): CheckReport => throw new \RuntimeException('password=hunter2'));
        try {
            $tool->call([]);
            self::fail();
        } catch (\Watcher\Mcp\ToolError $e) {
            self::assertSame('internal_error', $e->errorCode);
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function testItIsOpenWorldAndReadOnlyByContract(): void
    {
        $t = new CheckTool(static fn (): CheckReport => new CheckReport([]));
        self::assertTrue($t->openWorld());
        self::assertSame('inspect', $t->group());
    }
}
