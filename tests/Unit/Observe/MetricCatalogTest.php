<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use PHPUnit\Framework\TestCase;
use Watcher\Observe\MetricCatalog;

final class MetricCatalogTest extends TestCase
{
    public function testNamesTypesAndLabelsEqualTheContractTable(): void
    {
        $o = ['reported', 'quiet', 'baselined', 'failed', 'skipped_done', 'skipped_locked'];
        $sev = ['info', 'warn', 'error', 'critical'];
        $expected = [
            'watcher_info' => ['gauge', 'version'],
            'watcher_last_run_timestamp_seconds' => ['gauge', null],
            'watcher_last_success_timestamp_seconds' => ['gauge', null],
            'watcher_last_run_duration_seconds' => ['gauge', null],
            'watcher_metrics_written_timestamp_seconds' => ['gauge', null],
            'watcher_last_run_outcome' => ['gauge', 'outcome'],
            'watcher_runs_total' => ['counter', 'outcome'],
            'watcher_last_run_findings' => ['gauge', 'severity'],
            'watcher_last_run_new_findings' => ['gauge', 'severity'],
            'watcher_last_run_groups' => ['gauge', 'trend'],
            'watcher_last_run_events_read' => ['gauge', null],
            'watcher_last_run_groups_total' => ['gauge', null],
            'watcher_last_run_candidates' => ['gauge', null],
            'watcher_last_run_truncated' => ['gauge', null],
            'watcher_heartbeat_age_seconds' => ['gauge', null],
            'watcher_sink_failures_total' => ['counter', 'sink'],
            'watcher_delivery_failures_total' => ['counter', 'channel'],
        ];

        $actual = [];
        foreach (MetricCatalog::METRICS as $name => $m) {
            $actual[$name] = [$m['type'], $m['label']];
            self::assertNotSame('', $m['help']);
        }
        self::assertSame($expected, $actual);
        self::assertSame($o, MetricCatalog::METRICS['watcher_runs_total']['values']);
        self::assertSame($o, MetricCatalog::METRICS['watcher_last_run_outcome']['values']);
        self::assertSame($sev, MetricCatalog::METRICS['watcher_last_run_findings']['values']);
        self::assertSame(['new', 'grown', 'unchanged', 'expected'], MetricCatalog::METRICS['watcher_last_run_groups']['values']);
        self::assertSame(['log', 'file', 'http', 'prometheus'], MetricCatalog::METRICS['watcher_sink_failures_total']['values']);
        self::assertSame(['slack', 'telegram', 'discord'], MetricCatalog::METRICS['watcher_delivery_failures_total']['values']);
    }

}
