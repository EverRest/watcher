<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Watcher\Incident\KnownIssue;
use Watcher\Report\EventLog;
use Watcher\Report\ReportRenderer;
use Watcher\Report\Week;
use Watcher\Report\WeeklyReport;

final class WeeklyReportTest extends TestCase
{
    public function testBuildsFromFixture(): void
    {
        $path = sys_get_temp_dir() . '/watcher-wr-' . bin2hex(random_bytes(4)) . '.jsonl';
        copy(__DIR__ . '/../../Fixtures/report/week-2026-W43.jsonl', $path);
        $file = ['enabled' => true, 'path' => $path, 'rotate' => 'size', 'max_bytes' => 1048576, 'max_files' => 3, 'retention_days' => 30, 'include_text' => false];
        $snap = (new EventLog($file, 'orders', 'int'))->read(Week::fromIso('2026-W43'));
        $known = [new KnownIssue('3fa9c1d2e4b7', 'ABC-123', false, null)];
        $data = (new WeeklyReport())->build($snap, Week::fromIso('2026-W43'), 'orders', 'int', $known, 5, 20);
        self::assertNotNull($data);
        self::assertSame(412000, $data->tokensIn);
        self::assertSame(38000, $data->tokensOut);
        self::assertEqualsWithDelta(3.27, $data->costUsd, 0.001);
        self::assertSame(3, $data->muted);
        $text = (new ReportRenderer())->render($data)->text;
        self::assertStringContainsString('week 2026-W43', $text);
        self::assertStringContainsString('(known: ABC-123)', $text);
        @unlink($path);
    }

    public function testNoRunsWeekReturnsNull(): void
    {
        $path = sys_get_temp_dir() . '/watcher-empty-' . bin2hex(random_bytes(4)) . '.jsonl';
        file_put_contents($path, '');
        $file = ['enabled' => true, 'path' => $path, 'rotate' => 'size', 'max_bytes' => 1048576, 'max_files' => 3, 'retention_days' => 30, 'include_text' => false];
        $snap = (new EventLog($file, 'orders', 'int'))->read(Week::fromIso('2026-W43'));
        self::assertNull((new WeeklyReport())->build($snap, Week::fromIso('2026-W43'), 'orders', 'int', [], 5, 20));
        @unlink($path);
    }
}
