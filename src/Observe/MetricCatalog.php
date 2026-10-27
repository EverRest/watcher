<?php

declare(strict_types=1);

namespace Watcher\Observe;

/** The closed Prometheus metric catalog (specs/013 contracts/dashboards.md). `values` null = open label (version). */
final class MetricCatalog
{
    public const OUTCOMES = ['reported', 'quiet', 'baselined', 'failed', 'skipped_done', 'skipped_locked'];
    public const SEVERITIES = ['info', 'warn', 'error', 'critical'];
    public const TRENDS = ['new', 'grown', 'unchanged', 'expected'];

    /** @var array<string, array{type: string, help: string, label: ?string, values: ?list<string>}> */
    public const METRICS = [
        'watcher_info' => ['type' => 'gauge', 'help' => 'Always 1; carries the package version.', 'label' => 'version', 'values' => null],
        'watcher_last_run_timestamp_seconds' => ['type' => 'gauge', 'help' => 'Unix time of the last run event.', 'label' => null, 'values' => []],
        'watcher_last_success_timestamp_seconds' => ['type' => 'gauge', 'help' => 'Unix time of the last reported, quiet or baselined run.', 'label' => null, 'values' => []],
        'watcher_last_run_duration_seconds' => ['type' => 'gauge', 'help' => 'Duration of the last run in seconds.', 'label' => null, 'values' => []],
        'watcher_metrics_written_timestamp_seconds' => ['type' => 'gauge', 'help' => 'Unix time this file was written.', 'label' => null, 'values' => []],
        'watcher_last_run_outcome' => ['type' => 'gauge', 'help' => '1 for the outcome of the last run, 0 for the others.', 'label' => 'outcome', 'values' => self::OUTCOMES],
        'watcher_runs_total' => ['type' => 'counter', 'help' => 'Runs by outcome.', 'label' => 'outcome', 'values' => self::OUTCOMES],
        'watcher_last_run_findings' => ['type' => 'gauge', 'help' => 'Findings of the last run by severity.', 'label' => 'severity', 'values' => self::SEVERITIES],
        'watcher_last_run_new_findings' => ['type' => 'gauge', 'help' => 'Findings of the last run whose group is new, by severity.', 'label' => 'severity', 'values' => self::SEVERITIES],
        'watcher_last_run_groups' => ['type' => 'gauge', 'help' => 'Groups of the last run by trend.', 'label' => 'trend', 'values' => self::TRENDS],
        'watcher_last_run_events_read' => ['type' => 'gauge', 'help' => 'Log events read in the last run.', 'label' => null, 'values' => []],
        'watcher_last_run_groups_total' => ['type' => 'gauge', 'help' => 'Groups in the last run.', 'label' => null, 'values' => []],
        'watcher_last_run_candidates' => ['type' => 'gauge', 'help' => 'Candidates sent to analysis in the last run.', 'label' => null, 'values' => []],
        'watcher_last_run_truncated' => ['type' => 'gauge', 'help' => '1 when the last run hit a limit.', 'label' => null, 'values' => []],
        'watcher_heartbeat_age_seconds' => ['type' => 'gauge', 'help' => 'Heartbeat age at the time of the run.', 'label' => null, 'values' => []],
        'watcher_sink_failures_total' => ['type' => 'counter', 'help' => 'Sink write failures by sink.', 'label' => 'sink', 'values' => ['log', 'file', 'http', 'prometheus']],
        'watcher_delivery_failures_total' => ['type' => 'counter', 'help' => 'Notification delivery failures by channel.', 'label' => 'channel', 'values' => ['slack', 'telegram', 'discord']],
    ];

    /** Upper bound of series per service. */
    public static function seriesCount(): int
    {
        $n = 0;
        foreach (self::METRICS as $m) {
            $n += $m['values'] === null || $m['values'] === [] ? 1 : count($m['values']);
        }

        return $n;
    }
}
