<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Closure;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Throwable;

/** Writes the metric catalog as a Prometheus textfile (format 0.0.4), replaced atomically. */
final class PrometheusSink implements EventSink
{
    /**
     * @param Closure(): array{runs: array<string, float|int>, sink_failures: array<string, float|int>, delivery_failures: array<string, float|int>} $counters
     */
    public function __construct(
        private readonly string $dir,
        private readonly string $name,
        private readonly int $fileMode,
        private readonly ClockInterface $clock,
        private readonly Closure $counters,
    ) {
    }

    public function emit(array $events): void
    {
        $text = $this->render($events);
        $tmp = false;
        try {
            if (!is_dir($this->dir) || !is_writable($this->dir)) {
                throw new SinkFailed('prometheus directory not writable');
            }
            $tmp = tempnam($this->dir, '.' . $this->name . '.tmp');
            if ($tmp === false || realpath(dirname($tmp)) !== realpath($this->dir)) {
                throw new SinkFailed('prometheus temp file failed');
            }
            if (file_put_contents($tmp, $text) !== strlen($text) || !chmod($tmp, $this->fileMode) || !rename($tmp, $this->dir . '/' . $this->name . '.prom')) {
                throw new SinkFailed('prometheus write failed');
            }
        } catch (Throwable $e) {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }

            throw $e instanceof SinkFailed ? new SinkFailed($e->getMessage()) : new SinkFailed('prometheus write failed');
        }
    }

    /** @param list<array<string, mixed>> $events */
    private function render(array $events): string
    {
        $by = [];
        foreach ($events as $e) {
            $by[(string) ($e['event_type'] ?? '')][] = $e;
        }
        $run = $by['run'][0] ?? [];
        $first = $run !== [] ? $run : ($events[0] ?? []);
        $common = [
            'service' => (string) ($first['service'] ?? 'unset'),
            'environment' => (string) ($first['environment'] ?? 'unset'),
        ];

        /** @var array<string, list<array{string, float|int}>> $m metric => [[labelValue, value]] */
        $m = [];
        $put = static function (string $name, float|int|null $v, string $lv = '') use (&$m): void {
            if ($v !== null && is_finite((float) $v)) {
                $m[$name][] = [$lv, $v];
            }
        };

        $put('watcher_info', 1, (string) ($first['version'] ?? 'unknown'));
        $put('watcher_metrics_written_timestamp_seconds', $this->clock->now()->getTimestamp());

        $outcome = (string) ($run['outcome'] ?? '');
        $skipped = in_array($outcome, ['skipped_done', 'skipped_locked'], true);
        if ($run !== []) {
            $put('watcher_last_run_timestamp_seconds', $this->ts($run['ts'] ?? null));
        }
        if ($run !== [] && !$skipped) {
            $put('watcher_last_run_duration_seconds', self::num($run['duration_ms'] ?? null) === null ? null : self::num($run['duration_ms']) / 1000);
            foreach (MetricCatalog::OUTCOMES as $o) {
                $put('watcher_last_run_outcome', $o === $outcome ? 1 : 0, $o);
            }
            foreach (['events_read', 'groups_total', 'candidates', 'cost_usd'] as $k) {
                $put('cost_usd' === $k ? 'watcher_last_run_cost_usd' : 'watcher_last_run_' . $k, self::num($run[$k] ?? null));
            }
            $put('watcher_last_run_truncated', isset($run['truncated']) ? (int) (bool) $run['truncated'] : null);
            foreach (['in' => 'tokens_in', 'out' => 'tokens_out', 'cached' => 'tokens_cached'] as $d => $k) {
                $put('watcher_last_run_tokens', self::num($run[$k] ?? null), $d);
            }
            foreach (MetricCatalog::SEVERITIES as $s) {
                $put('watcher_last_run_findings', self::num($run['findings_by_severity'][$s] ?? null), $s);
            }
            foreach (MetricCatalog::TRENDS as $t) {
                $put('watcher_last_run_groups', self::num($run['groups_by_trend'][$t] ?? null), $t);
            }
            $new = array_fill_keys(MetricCatalog::SEVERITIES, 0);
            foreach ($by['finding'] ?? [] as $f) {
                if (($f['group_trend'] ?? null) === 'new' && isset($new[$f['severity'] ?? ''])) {
                    ++$new[$f['severity']];
                }
            }
            foreach ($new as $s => $n) {
                $put('watcher_last_run_new_findings', $n, $s);
            }
        } elseif ($run !== []) {
            foreach (MetricCatalog::OUTCOMES as $o) {
                $put('watcher_last_run_outcome', $o === $outcome ? 1 : 0, $o);
            }
        }

        $hbOk = null;
        if (isset($by['heartbeat'][0])) {
            $h = $by['heartbeat'][0];
            $put('watcher_heartbeat_age_seconds', self::num($h['age_seconds'] ?? null));
            $hbOk = $this->ts($h['last_ok_at'] ?? null);
        }
        $success = in_array($outcome, ['reported', 'quiet', 'baselined'], true)
            ? max($this->ts($run['ts'] ?? null) ?? 0, $hbOk ?? 0) : $hbOk;
        $put('watcher_last_success_timestamp_seconds', $success);

        if (isset($by['budget'][0])) {
            $b = $by['budget'][0];
            $put('watcher_budget_spent_usd', self::num($b['spent_today_usd'] ?? null));
            $put('watcher_budget_limit_usd', self::num($b['daily_budget_usd'] ?? null));
            $ratios = array_filter([self::num($b['usd_ratio'] ?? null), self::num($b['token_ratio'] ?? null)], static fn ($r) => $r !== null);
            $put('watcher_budget_ratio', $ratios === [] ? null : max($ratios));
        }

        if ($skipped) {
            $this->carryOver($m);
        }

        try {
            $c = ($this->counters)();
            foreach (['runs' => 'watcher_runs_total', 'sink_failures' => 'watcher_sink_failures_total', 'delivery_failures' => 'watcher_delivery_failures_total'] as $k => $metric) {
                foreach ($c[$k] as $lv => $v) {
                    if (in_array((string) $lv, MetricCatalog::METRICS[$metric]['values'], true)) {
                        $put($metric, self::num($v), (string) $lv);
                    }
                }
            }
        } catch (Throwable) {
            // not a write failure: counters unknown, those metrics are omitted
        }

        $out = '';
        foreach (MetricCatalog::METRICS as $name => $def) {
            if (!isset($m[$name])) {
                continue;
            }
            $out .= "# HELP $name {$def['help']}\n# TYPE $name {$def['type']}\n";
            foreach ($m[$name] as [$lv, $v]) {
                $labels = $common + ($def['label'] !== null ? [$def['label'] => $lv] : []);
                $pairs = [];
                foreach ($labels as $k => $val) {
                    $pairs[] = $k . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $val) . '"';
                }
                $out .= $name . '{' . implode(',', $pairs) . '} ' . self::fmt($v) . "\n";
            }
        }

        return $out;
    }

    /**
     * A skipped run says nothing about the last real run: keep its samples from the previous file.
     *
     * @param array<string, list<array{string, float|int}>> $m
     */
    private function carryOver(array &$m): void
    {
        $text = @file_get_contents($this->dir . '/' . $this->name . '.prom');
        if (!is_string($text)) {
            return;
        }
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(\w+)(?:\{(.*)\})? (-?[0-9.]+)$/', $line, $x) !== 1 || !isset(MetricCatalog::METRICS[$x[1]]) || isset($m[$x[1]])) {
                continue;
            }
            $n = $x[1];
            if (!str_starts_with($n, 'watcher_budget_') && !str_starts_with($n, 'watcher_last_run_') && $n !== 'watcher_heartbeat_age_seconds' && $n !== 'watcher_last_success_timestamp_seconds') {
                continue;
            }
            if ($n === 'watcher_last_run_outcome' || $n === 'watcher_last_run_timestamp_seconds') {
                continue;
            }
            $label = MetricCatalog::METRICS[$n]['label'];
            $lv = '';
            if ($label !== null) {
                if (preg_match('/' . $label . '="([^"]*)"/', $x[2], $lm) !== 1 || !in_array($lm[1], MetricCatalog::METRICS[$n]['values'] ?? [], true)) {
                    continue;
                }
                $lv = $lm[1];
            }
            $carried[$n][] = [$lv, str_contains($x[3], '.') ? (float) $x[3] : (int) $x[3]];
        }
        foreach ($carried ?? [] as $n => $rows) {
            $m[$n] = $rows;
        }
    }

    private static function num(mixed $v): float|int|null
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) ? $v : null;
    }

    private function ts(mixed $v): ?int
    {
        try {
            return is_string($v) ? (new DateTimeImmutable($v))->getTimestamp() : null;
        } catch (Throwable) {
            return null; // not a write failure: an unparseable timestamp is an unknown value
        }
    }

    private static function fmt(float|int $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        $s = rtrim(rtrim(number_format($v, 12, '.', ''), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
