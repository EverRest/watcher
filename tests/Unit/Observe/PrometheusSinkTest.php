<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Watcher\Observe\MetricCatalog;
use Watcher\Observe\PrometheusSink;
use Watcher\Observe\SinkFailed;
use Watcher\Tests\Fakes\FrozenClock;

final class PrometheusSinkTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/watcher-prom-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @chmod($this->dir, 0700);
        rmdir($this->dir);
    }

    /** @return list<array<string, mixed>> */
    private function fixture(): array
    {
        /** @var list<array<string, mixed>> $e */
        $e = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/observe/events.json'), true, 512, JSON_THROW_ON_ERROR);

        return $e;
    }

    private function sink(?Closure $counters = null, ?string $dir = null): PrometheusSink
    {
        return new PrometheusSink(
            $dir ?? $this->dir,
            'watcher',
            0644,
            new FrozenClock(new DateTimeImmutable('2026-10-24T06:05:30Z')),
            $counters ?? static fn (): array => [
                'runs' => ['reported' => 7, 'failed' => 1, 'bogus' => 3],
                'sink_failures' => ['http' => 2],
                'delivery_failures' => ['slack' => 1],
            ],
        );
    }

    /**
     * Tiny exposition parser. Fails on anything not HELP/TYPE/sample.
     *
     * @return array{series: list<array{name: string, labels: array<string, string>, value: string}>, types: array<string, string>, help: array<string, int>}
     */
    private function parse(string $text): array
    {
        self::assertStringEndsWith("\n", $text);
        $series = [];
        $types = [];
        $help = [];
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            if (preg_match('/^# HELP (\w+) .+$/', $line, $m) === 1) {
                $help[$m[1]] = ($help[$m[1]] ?? 0) + 1;
            } elseif (preg_match('/^# TYPE (\w+) (gauge|counter)$/', $line, $m) === 1) {
                self::assertArrayNotHasKey($m[1], $types);
                $types[$m[1]] = $m[2];
            } elseif (preg_match('/^(\w+)(?:\{(.*)\})? (-?[0-9.]+)$/', $line, $m) === 1) {
                $labels = [];
                if ($m[2] !== '') {
                    preg_match_all('/(\w+)="((?:[^"\\\\]|\\\\.)*)"/', $m[2], $lm, PREG_SET_ORDER);
                    foreach ($lm as $l) {
                        $labels[$l[1]] = $l[2];
                    }
                }
                $series[] = ['name' => $m[1], 'labels' => $labels, 'value' => $m[3]];
            } else {
                self::fail('unparsable line: ' . $line);
            }
        }

        return ['series' => $series, 'types' => $types, 'help' => $help];
    }

    /** @return array<string, string> "name{k=v,...}" => value */
    private function samples(string $text): array
    {
        $out = [];
        foreach ($this->parse($text)['series'] as $s) {
            $l = $s['labels'];
            unset($l['service'], $l['environment']);
            ksort($l);
            $out[$s['name'] . ($l === [] ? '' : '{' . http_build_query($l, '', ',') . '}')] = $s['value'];
        }

        return $out;
    }

    private function read(): string
    {
        return (string) file_get_contents($this->dir . '/watcher.prom');
    }

    public function testDerivesTheCatalogFromTheEvents(): void
    {
        $this->sink()->emit($this->fixture());
        $text = $this->read();
        $p = $this->parse($text);
        $s = $this->samples($text);

        foreach ($p['types'] as $name => $type) {
            self::assertSame($type, MetricCatalog::METRICS[$name]['type']);
            self::assertSame(1, $p['help'][$name]);
        }
        self::assertSame('1', $s['watcher_info{version=0.1.70}']);
        self::assertSame((string) (new DateTimeImmutable('2026-10-24T06:05:00Z'))->getTimestamp(), $s['watcher_last_run_timestamp_seconds']);
        self::assertSame((string) (new DateTimeImmutable('2026-10-24T06:05:00Z'))->getTimestamp(), $s['watcher_last_success_timestamp_seconds']);
        self::assertSame((string) (new DateTimeImmutable('2026-10-24T06:05:30Z'))->getTimestamp(), $s['watcher_metrics_written_timestamp_seconds']);
        self::assertSame('4.2', $s['watcher_last_run_duration_seconds']);
        self::assertSame('1', $s['watcher_last_run_outcome{outcome=reported}']);
        self::assertSame('0', $s['watcher_last_run_outcome{outcome=skipped_locked}']);
        self::assertSame('1', $s['watcher_last_run_findings{severity=critical}']);
        self::assertSame('1', $s['watcher_last_run_new_findings{severity=critical}']);
        self::assertSame('0', $s['watcher_last_run_new_findings{severity=warn}']);
        self::assertSame('35', $s['watcher_last_run_groups{trend=unchanged}']);
        self::assertSame('1200', $s['watcher_last_run_events_read']);
        self::assertSame('0', $s['watcher_last_run_truncated']);
        self::assertSame('86400', $s['watcher_heartbeat_age_seconds']);
        self::assertSame('7', $s['watcher_runs_total{outcome=reported}']);
        self::assertSame('2', $s['watcher_sink_failures_total{sink=http}']);
        self::assertSame('1', $s['watcher_delivery_failures_total{channel=slack}']);
        self::assertStringNotContainsString('bogus', $text);
        self::assertStringContainsString('{service="bff-a",environment="production"', $text);
    }

    public function testFailedRunKeepsThePreviousSuccessFromTheHeartbeat(): void
    {
        $events = $this->fixture();
        $events[0]['outcome'] = 'failed';
        $this->sink()->emit($events);
        $s = $this->samples($this->read());
        self::assertSame((string) (new DateTimeImmutable('2026-10-23T06:05:00Z'))->getTimestamp(), $s['watcher_last_success_timestamp_seconds']);
        self::assertSame('1', $s['watcher_last_run_outcome{outcome=failed}']);
    }

    public function testSuccessWithoutHeartbeatUsesTheRunTsAndFailureOmitsIt(): void
    {
        $run = [$this->fixture()[0]];
        $this->sink()->emit($run);
        self::assertSame(
            (string) (new DateTimeImmutable('2026-10-24T06:05:00Z'))->getTimestamp(),
            $this->samples($this->read())['watcher_last_success_timestamp_seconds'],
        );

        $run[0]['outcome'] = 'failed';
        unlink($this->dir . '/watcher.prom');
        $this->sink()->emit($run);
        self::assertArrayNotHasKey('watcher_last_success_timestamp_seconds', $this->samples($this->read()));
    }

    public function testSkippedRunCarriesOverThePreviousRealRun(): void
    {
        $this->sink()->emit($this->fixture());
        $before = $this->samples($this->read());
        $skip = [$this->fixture()[0]];
        $skip[0]['outcome'] = 'skipped_locked';
        $skip[0]['ts'] = '2026-10-24T07:00:00Z';
        $this->sink()->emit($skip);
        $s = $this->samples($this->read());

        self::assertSame('1', $s['watcher_last_run_outcome{outcome=skipped_locked}']);
        self::assertSame('0', $s['watcher_last_run_outcome{outcome=reported}']);
        self::assertSame((string) (new DateTimeImmutable('2026-10-24T07:00:00Z'))->getTimestamp(), $s['watcher_last_run_timestamp_seconds']);
        foreach (['watcher_last_success_timestamp_seconds', 'watcher_last_run_findings{severity=critical}', 'watcher_last_run_groups{trend=unchanged}', 'watcher_last_run_new_findings{severity=critical}', 'watcher_heartbeat_age_seconds'] as $k) {
            self::assertSame($before[$k], $s[$k], $k);
        }
    }

    public function testSkippedRunWithoutPreviousFileOmitsTheCarriedMetrics(): void
    {
        $skip = [$this->fixture()[0]];
        $skip[0]['outcome'] = 'skipped_done';
        $this->sink()->emit($skip);
        $s = $this->samples($this->read());
        self::assertArrayNotHasKey('watcher_last_success_timestamp_seconds', $s);
        self::assertArrayNotHasKey('watcher_last_run_findings{severity=critical}', $s);
    }

    public function testLastSuccessIsASingleSeriesTakingTheNewerOfRunAndHeartbeat(): void
    {
        $events = $this->fixture();
        $events[0]['ts'] = '2026-10-24T06:05:00Z';
        foreach ($events as &$e) {
            if (($e['event_type'] ?? '') === 'heartbeat') {
                $e['last_ok_at'] = '2026-10-20T00:00:00Z';
            }
        }
        unset($e);
        $this->sink()->emit($events);
        $text = $this->read();
        self::assertSame(1, substr_count($text, "\nwatcher_last_success_timestamp_seconds{"));
        self::assertSame((string) (new DateTimeImmutable('2026-10-24T06:05:00Z'))->getTimestamp(), $this->samples($text)['watcher_last_success_timestamp_seconds']);
    }

    public function testNewFindingsAreZeroWithoutFindingEvents(): void
    {
        $this->sink()->emit([$this->fixture()[0]]);
        self::assertSame('0', $this->samples($this->read())['watcher_last_run_new_findings{severity=critical}']);
    }

    public function testCountersThatThrowAreOmittedNotFatal(): void
    {
        $this->sink(static function (): array {
            throw new RuntimeException('store down');
        })->emit($this->fixture());
        $text = $this->read();
        self::assertStringNotContainsString('_total{', str_replace('watcher_last_run_groups_total', '', $text));
        self::assertStringContainsString('watcher_last_run_events_read', $text);
    }

    public function testLabelValuesAreEscaped(): void
    {
        $events = $this->fixture();
        foreach ($events as &$e) {
            $e['service'] = "a\"b\\c\nd";
        }
        unset($e);
        $this->sink()->emit($events);
        self::assertStringContainsString('service="a\"b\\\\c\nd"', $this->read());
    }

    public function testSeriesAreBoundedAndNoUnboundedValueBecomesALabel(): void
    {
        $events = $this->fixture();
        $events[0]['groups_total'] = 5000;
        $events[0]['groups_by_trend'] = ['new' => 1000, 'grown' => 1000, 'unchanged' => 2000, 'expected' => 1000];
        for ($i = 0; $i < 500; $i++) {
            $f = $this->fixture()[1];
            $f['fingerprint'] = sprintf('%012x', $i);
            $f['title'] = 'Title ' . $i;
            $f['severity'] = ['info', 'warn', 'error', 'critical'][$i % 4];
            $events[] = $f;
        }
        $this->sink()->emit($events);
        $text = $this->read();
        $p = $this->parse($text);

        self::assertLessThanOrEqual(MetricCatalog::seriesCount(), count($p['series']));
        self::assertSame('126', $this->samples($text)['watcher_last_run_new_findings{severity=critical}']);
        $allowed = [];
        foreach (MetricCatalog::METRICS as $m) {
            foreach ($m['values'] ?? [] as $v) {
                $allowed[$v] = true;
            }
        }
        foreach ($p['series'] as $s) {
            foreach ($s['labels'] as $k => $v) {
                if (in_array($k, ['service', 'environment'], true)) {
                    self::assertContains($v, ['bff-a', 'production']);
                } elseif ($k !== 'version') {
                    self::assertArrayHasKey($v, $allowed);
                }
            }
        }
        self::assertStringNotContainsString('Title ', $text);
        self::assertStringNotContainsString('0123456789abcdef', $text);
    }

    public function testFileModeAndNoLeftovers(): void
    {
        $this->sink()->emit($this->fixture());
        self::assertSame(0644, fileperms($this->dir . '/watcher.prom') & 0777);
        self::assertSame(['.', '..', 'watcher.prom'], scandir($this->dir));
    }

    public function testRewriteNeverExposesAPartialFile(): void
    {
        $sink = $this->sink();
        $events = $this->fixture();
        $path = $this->dir . '/watcher.prom';
        $seen = 0;
        for ($i = 0; $i < 1000; $i++) {
            $events[0]['events_read'] = $i;
            $sink->emit($events);
            $text = @file_get_contents($path);
            self::assertIsString($text);
            $this->parse($text);
            self::assertSame((string) $i, $this->samples($text)['watcher_last_run_events_read']);
            ++$seen;
        }
        self::assertSame(1000, $seen);
        self::assertCount(3, scandir($this->dir) ?: []);
    }

    public function testMissingDirectoryThrowsSinkFailedWithoutThePath(): void
    {
        $dir = $this->dir . '/nope-secret';
        try {
            $this->sink(null, $dir)->emit($this->fixture());
            self::fail('expected SinkFailed');
        } catch (SinkFailed $e) {
            self::assertStringNotContainsString('nope-secret', $e->getMessage());
            self::assertStringNotContainsString($this->dir, $e->getMessage());
        }
    }

    public function testUnwritableDirectoryThrowsSinkFailedAndLeavesNothing(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores permissions');
        }
        chmod($this->dir, 0500);
        $this->expectException(SinkFailed::class);
        $this->sink()->emit($this->fixture());
    }
}
