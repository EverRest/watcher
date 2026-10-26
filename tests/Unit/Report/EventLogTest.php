<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Watcher\Report\EventLog;
use Watcher\Report\EventLogException;
use Watcher\Report\Week;

final class EventLogTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/watcher-report-' . bin2hex(random_bytes(4)) . '.jsonl';
        copy(__DIR__ . '/../../Fixtures/report/week-2026-W43.jsonl', $this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** @return array{enabled: bool, path: string, rotate: string, max_bytes: int, max_files: int, retention_days: int, include_text: bool} */
    private function fileConfig(): array
    {
        return [
            'enabled' => true,
            'path' => $this->path,
            'rotate' => 'size',
            'max_bytes' => 1048576,
            'max_files' => 3,
            'retention_days' => 30,
            'include_text' => false,
        ];
    }

    public function testReadsFixtureWeek(): void
    {
        $log = new EventLog($this->fileConfig(), 'orders', 'int');
        $snap = $log->read(Week::fromIso('2026-W43'));
        self::assertCount(5, $snap->runs);
        self::assertGreaterThan(0, count($snap->groups));
        self::assertSame(0, $snap->skippedTotal());
    }

    public function testDryRunAndSkippedOutcomesIgnored(): void
    {
        file_put_contents($this->path, '{"schema":"watcher.event.v1","event_type":"run","event_id":"a000000000000001-1","ts":"2026-10-20T06:05:00Z","service":"orders","environment":"int","run_id":"a000000000000001","version":"1.0.0","window_start":"2026-10-19T06:00:00Z","window_end":"2026-10-20T06:00:00Z","outcome":"skipped_done","dry_run":false,"release":"1.4.1","tokens_in":1,"tokens_out":1,"cost_usd":0.1,"muted":0}' . "\n", FILE_APPEND);
        $snap = (new EventLog($this->fileConfig(), 'orders', 'int'))->read(Week::fromIso('2026-W43'));
        self::assertCount(5, $snap->runs);
    }

    public function testMissingFileFailsLoud(): void
    {
        $cfg = $this->fileConfig();
        $cfg['path'] = '/nonexistent/watcher-events.jsonl';
        $this->expectException(EventLogException::class);
        $this->expectExceptionMessage('observe.file.path unreadable');
        (new EventLog($cfg, 'orders', 'int'))->read(Week::fromIso('2026-W43'));
    }
}
