<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Cli\Application;
use Watcher\Config\InvalidConfig;
use Watcher\Fleet\FleetResult;
use Watcher\Fleet\FleetStatus;
use Watcher\Fleet\ServiceResult;
use Watcher\Operate\CheckReport;
use Watcher\Operate\CheckResult;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\RunOptions;
use Watcher\Operate\RunSummaryData;
use Watcher\RunOutcome;
use Watcher\RunResult;

final class CliTest extends TestCase
{
    private string $cfg;

    private string $stdout = '';

    /** @var list<RunOptions> */
    private array $options = [];

    protected function setUp(): void
    {
        $this->cfg = sys_get_temp_dir() . '/watcher-cli-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->cfg, '<?php return ["service" => "svc", "secret" => "s3cr3t-value"];');
    }

    protected function tearDown(): void
    {
        @unlink($this->cfg);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, list<array{array<string, mixed>, ?DateTimeImmutable}>}
     */
    private function exec(array $args, RunResult|InvalidConfig $outcome): array
    {
        $calls = [];
        $this->options = [];
        $factory = function (array $config, RunOptions $options) use ($outcome, &$calls): callable {
            $this->options[] = $options;
            if ($outcome instanceof InvalidConfig) {
                throw $outcome;
            }

            return static function (?DateTimeImmutable $now) use ($config, $outcome, &$calls): RunResult {
                $calls[] = [$config, $now];

                return $outcome;
            };
        };
        $err = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        self::assertIsResource($out);
        $code = (new Application($factory, $err, $out))->run(['watcher', ...$args]);
        rewind($err);
        rewind($out);
        $this->stdout = (string) stream_get_contents($out);

        return [$code, (string) stream_get_contents($err), $calls];
    }

    public function testExitCodesMapFromRunResult(): void
    {
        foreach ([[RunOutcome::Quiet, 0], [RunOutcome::Failed, 1]] as [$outcome, $expected]) {
            [$code, , $calls] = $this->exec(['run', '--config=' . $this->cfg], new RunResult($outcome));
            self::assertSame($expected, $code);
            self::assertCount(1, $calls);
            self::assertSame('svc', $calls[0][0]['service']);
            self::assertNull($calls[0][1]);
        }
    }

    public function testNowIsPassedThrough(): void
    {
        [$code, , $calls] = $this->exec(['run', '--config=' . $this->cfg, '--now=2026-10-23T06:00:00Z'], new RunResult(RunOutcome::Quiet));
        self::assertSame(0, $code);
        self::assertSame('2026-10-23T06:00:00+00:00', $calls[0][1]?->format(DATE_ATOM));
    }

    public function testInvalidNowExitsTwoWithoutRunning(): void
    {
        [$code, $err, $calls] = $this->exec(['run', '--config=' . $this->cfg, '--now=yesterday'], new RunResult(RunOutcome::Quiet));
        self::assertSame(2, $code);
        self::assertSame([], $calls);
        self::assertStringContainsString('--now', $err);
    }

    public function testInvalidConfigPrintsOneLineWithoutValues(): void
    {
        [$code, $err, $calls] = $this->exec(['run', '--config=' . $this->cfg], new InvalidConfig('analysis.api_key is required'));
        self::assertSame(2, $code);
        self::assertSame("invalid config: analysis.api_key is required\n", $err);
        self::assertStringNotContainsString('s3cr3t-value', $err);
        self::assertSame([], $calls);
    }

    public function testUsageOnUnknownCommandMissingConfigOrUnreadableFile(): void
    {
        foreach ([['bogus'], [], ['run'], ['run', '--config=/nonexistent/x.php']] as $args) {
            [$code, $err, $calls] = $this->exec($args, new RunResult(RunOutcome::Quiet));
            self::assertSame(2, $code);
            self::assertStringContainsString('usage:', $err);
            self::assertSame([], $calls);
        }
    }

    public function testConfigFileNotReturningArrayIsExitTwo(): void
    {
        file_put_contents($this->cfg, '<?php return "nope";');
        [$code, $err] = $this->exec(['run', '--config=' . $this->cfg], new RunResult(RunOutcome::Quiet));
        self::assertSame(2, $code);
        self::assertStringContainsString('invalid config:', $err);
    }

    public function testPrintsOneSummaryLineToStdoutForEveryOutcome(): void
    {
        $this->exec(['run', '--config=' . $this->cfg], new RunResult(RunOutcome::Quiet));
        self::assertSame("watcher: outcome=quiet\n", $this->stdout);

        $this->exec(['run', '--config=' . $this->cfg], new RunResult(RunOutcome::Failed, 'source failed: loki 401 Unauthorized'));
        self::assertSame("watcher: outcome=failed reason=\"source failed: loki 401 Unauthorized\"\n", $this->stdout);
    }

    public function testNothingIsPrintedToStdoutWhenTheConfigIsInvalid(): void
    {
        $this->exec(['run', '--config=' . $this->cfg], new InvalidConfig('service is required'));

        self::assertSame('', $this->stdout);
    }

    public function testRunOptionsReachTheFactory(): void
    {
        $this->exec(['run', '--config=' . $this->cfg, '--dry-run', '--estimate', '--json'], new RunResult(RunOutcome::Quiet));
        self::assertEquals(new RunOptions(true, true, true), $this->options[0]);

        $this->exec(['run', '--config=' . $this->cfg], new RunResult(RunOutcome::Quiet));
        self::assertEquals(RunOptions::default(), $this->options[0]);

        $this->exec(['run', '--config=' . $this->cfg, '--dry-run'], new RunResult(RunOutcome::Quiet));
        self::assertEquals(RunOptions::dryRun(), $this->options[0]);
    }

    public function testEstimateWithoutDryRunIsAUsageError(): void
    {
        [$code, $err, $calls] = $this->exec(['run', '--config=' . $this->cfg, '--estimate'], new RunResult(RunOutcome::Quiet));
        self::assertSame(2, $code);
        self::assertStringContainsString('--estimate requires --dry-run', $err);
        self::assertSame([], $calls);
        self::assertSame('', $this->stdout);
    }

    public function testJsonPrintsExactlyOneLineOnStdoutAndTheSummaryOnStderr(): void
    {
        $r = new RunResult(RunOutcome::Quiet, null, null, new RunSummaryData(RunOutcome::Quiet, 'svc'));
        [$code, $err] = $this->exec(['run', '--config=' . $this->cfg, '--json'], $r);

        self::assertSame(0, $code);
        self::assertSame(1, substr_count($this->stdout, "\n"));
        self::assertStringEndsWith("\n", $this->stdout);
        $j = json_decode($this->stdout, true);
        self::assertIsArray($j);
        self::assertSame('quiet', $j['outcome']);
        self::assertSame('svc', $j['service']);
        self::assertSame("watcher: outcome=quiet\n", $err);
    }

    public function testJsonLineIsPrintedAlsoForFailureWithTheSameExitCode(): void
    {
        $r = new RunResult(RunOutcome::Failed, 'source failed', null, new RunSummaryData(RunOutcome::Failed, 'svc', 'source_failed'));
        [$code] = $this->exec(['run', '--config=' . $this->cfg, '--json'], $r);

        self::assertSame(1, $code);
        $j = json_decode($this->stdout, true);
        self::assertIsArray($j);
        self::assertSame('failed', $j['outcome']);
        self::assertSame('source_failed', $j['reason']);
    }

    public function testJsonWithInvalidConfigPrintsTheSummaryWithShortCodeAndExitsTwo(): void
    {
        [$code, $err] = $this->exec(['run', '--config=' . $this->cfg, '--json'], new InvalidConfig('analysis.api_key is required'));

        self::assertSame(2, $code);
        self::assertSame(1, substr_count($this->stdout, "\n"));
        $j = json_decode($this->stdout, true);
        self::assertIsArray($j);
        self::assertSame('invalid_config', $j['outcome']);
        self::assertSame('invalid_config', $j['reason']);
        self::assertStringContainsString('analysis.api_key is required', $err);
        self::assertStringNotContainsString('api_key', $this->stdout);
    }

    public function testJsonWithoutSummaryDataStillPrintsOneLine(): void
    {
        $this->exec(['run', '--config=' . $this->cfg, '--json'], new RunResult(RunOutcome::Reported));
        $j = json_decode($this->stdout, true);
        self::assertIsArray($j);
        self::assertSame('reported', $j['outcome']);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function checkExec(array $args, CheckReport|InvalidConfig $report, ?bool &$post = null): array
    {
        $checkFactory = static function (array $config, bool $postTest) use ($report, &$post): CheckReport {
            $post = $postTest;
            if ($report instanceof InvalidConfig) {
                throw $report;
            }

            return $report;
        };
        $err = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        self::assertIsResource($out);
        $code = (new Application(static fn (): callable => throw new \LogicException('no run'), $err, $out, $checkFactory))->run(['watcher', ...$args]);
        rewind($err);
        rewind($out);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testCheckPrintsTheReportAndExitsWithItsCode(): void
    {
        $ok = new CheckReport([CheckResult::pass('config')]);
        [$code, $out, $err] = $this->checkExec(['check', '--config=' . $this->cfg], $ok, $post);
        self::assertSame(0, $code);
        self::assertSame($ok->text(), $out);
        self::assertSame('', $err);
        self::assertFalse($post);

        $bad = new CheckReport([CheckResult::pass('config'), CheckResult::fail('source', 'unauthorised')]);
        [$code, $out] = $this->checkExec(['check', '--config=' . $this->cfg, '--post-test'], $bad, $post);
        self::assertSame(1, $code);
        self::assertStringContainsString('unauthorised', $out);
        self::assertTrue($post);
    }

    public function testCheckJsonIsOneStdoutLineAndTextGoesToStderr(): void
    {
        $bad = new CheckReport([CheckResult::pass('config'), CheckResult::fail('source', 'unauthorised')]);
        [$code, $out, $err] = $this->checkExec(['check', '--config=' . $this->cfg, '--json'], $bad);

        self::assertSame(1, $code);
        self::assertSame(1, substr_count($out, "\n"));
        self::assertSame($bad->toArray(), json_decode($out, true));
        self::assertSame($bad->text(), $err);
    }

    public function testCheckWithInvalidConfigIsAConfigFailureReportWithExitOne(): void
    {
        [$code, $out] = $this->checkExec(['check', '--config=' . $this->cfg], new InvalidConfig('analysis.api_key is required'));

        self::assertSame(1, $code);
        self::assertStringContainsString('config', $out);
        self::assertStringContainsString('invalid: analysis.api_key is required', $out);
        self::assertStringContainsString('skip', strtolower($out));
    }

    public function testCheckUsageErrors(): void
    {
        $r = new CheckReport([CheckResult::pass('config')]);
        foreach ([['check'], ['check', '--config=' . $this->cfg, '--now=2026-10-23T06:00:00Z'], ['check', '--config=/nonexistent.php']] as $args) {
            [$code, , $err] = $this->checkExec($args, $r);
            self::assertSame(2, $code);
            self::assertStringContainsString('usage:', $err);
        }
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function statusExec(array $args, HeartbeatStatus|InvalidConfig $status): array
    {
        $statusFactory = static function (array $config) use ($status): HeartbeatStatus {
            if ($status instanceof InvalidConfig) {
                throw $status;
            }

            return $status;
        };
        $err = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        self::assertIsResource($out);
        $code = (new Application(static fn (): callable => throw new \LogicException('no run'), $err, $out, null, $statusFactory))->run(['watcher', ...$args]);
        rewind($err);
        rewind($out);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private static function hb(string $state): HeartbeatStatus
    {
        $now = new DateTimeImmutable('2026-10-24T09:12:55+00:00');
        $ok = new DateTimeImmutable('2026-10-24T06:00:41+00:00');
        $rec = $state === 'unknown' ? null : ['outcome' => $state === 'failed' ? 'failed' : 'quiet', 'finishedAt' => $ok->format(DATE_ATOM), 'durationSeconds' => 38, 'costUsd' => 0.0];

        return new HeartbeatStatus($state, $rec, $state === 'unknown' ? null : 11534, 129600, $now, $state === 'unknown' ? null : $ok, null);
    }

    public function testStatusExitCodesPerState(): void
    {
        foreach (['ok' => 0, 'stale' => 1, 'failed' => 1, 'unknown' => 3] as $state => $expected) {
            [$code, $out] = $this->statusExec(['status', '--config=' . $this->cfg], self::hb($state));
            self::assertSame($expected, $code, $state);
            self::assertStringContainsString("state:          $state", $out);
            self::assertStringContainsString('service:        svc', $out);
        }
    }

    public function testStatusPrintsBothComparedInstants(): void
    {
        [, $out] = $this->statusExec(['status', '--config=' . $this->cfg], self::hb('stale'));
        self::assertStringContainsString('compared:       now=2026-10-24T09:12:55+00:00 against last ok 2026-10-24T06:00:41+00:00', $out);
        self::assertStringContainsString('age=3h12m', $out);
        self::assertStringContainsString('limit=36h', $out);
        self::assertStringContainsString('outcome=quiet', $out);
    }

    public function testStatusJsonIsOneLine(): void
    {
        [$code, $out, $err] = $this->statusExec(['status', '--config=' . $this->cfg, '--json'], self::hb('ok'));
        self::assertSame(0, $code);
        self::assertSame(1, substr_count($out, "\n"));
        $j = json_decode($out, true);
        self::assertIsArray($j);
        self::assertSame(1, $j['schema']);
        self::assertSame('svc', $j['service']);
        self::assertSame('ok', $j['state']);
        self::assertSame(11534, $j['age_seconds']);
        self::assertSame(129600, $j['limit_seconds']);
        self::assertSame('2026-10-24T06:00:41+00:00', $j['last_ok_at']);
        self::assertSame('quiet', $j['last_run']['outcome']);
        self::assertStringContainsString('state:', $err);

        [, $out] = $this->statusExec(['status', '--config=' . $this->cfg, '--json'], self::hb('unknown'));
        $j = json_decode($out, true);
        self::assertNull($j['last_run']);
        self::assertNull($j['age_seconds']);
    }

    public function testStatusInvalidConfigAndUsage(): void
    {
        [$code, $out, $err] = $this->statusExec(['status', '--config=' . $this->cfg], new InvalidConfig('state.driver is required'));
        self::assertSame(2, $code);
        self::assertSame('', $out);
        self::assertSame("invalid config: state.driver is required\n", $err);

        [$code, , $err] = $this->statusExec(['status', '--config=' . $this->cfg, '--dry-run'], self::hb('ok'));
        self::assertSame(2, $code);
        self::assertStringContainsString('usage:', $err);
    }

    public function testUsageNamesAllThreeCommands(): void
    {
        [, $err] = $this->exec([], new RunResult(RunOutcome::Quiet));
        foreach (['watcher run', 'watcher check', 'watcher status', '--dry-run', '--estimate', '--json', '--post-test'] as $needle) {
            self::assertStringContainsString($needle, $err);
        }
    }

    public function testCheckOrStatusWithoutTheirFactoryIsUsage(): void
    {
        [$code, $err] = $this->exec(['check', '--config=' . $this->cfg], new RunResult(RunOutcome::Quiet));
        self::assertSame(2, $code);
        self::assertStringContainsString('usage:', $err);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string, list<array{string, RunOptions, list<string>, ?DateTimeImmutable}>}
     */
    private function execFleet(array $args, FleetResult|InvalidConfig $outcome): array
    {
        $calls = [];
        $factory = static function (string $config, RunOptions $options, array $only) use ($outcome, &$calls): callable {
            if ($outcome instanceof InvalidConfig) {
                throw $outcome;
            }

            return static function (?DateTimeImmutable $now) use ($config, $options, $only, $outcome, &$calls): FleetResult {
                $calls[] = [$config, $options, array_values($only), $now];

                return $outcome;
            };
        };
        $err = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        self::assertIsResource($out);
        $unused = static function (): callable {
            throw new \LogicException('not used');
        };
        $code = (new Application($unused, $err, $out, null, null, $factory))->run(['watcher', ...$args]);
        rewind($err);
        rewind($out);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err), $calls];
    }

    private static function fleetResult(RunOutcome ...$outcomes): FleetResult
    {
        $services = [];
        foreach ($outcomes as $i => $o) {
            $services[] = ServiceResult::ran('s' . $i, new RunResult($o));
        }

        return new FleetResult('platform', $services);
    }

    public function testFleetRunPassesConfigOptionsOnlyAndNowAndMapsTheExitCode(): void
    {
        [$code, $out, , $calls] = $this->execFleet(
            ['fleet:run', '--config=' . $this->cfg, '--dry-run', '--estimate', '--only=api, bff-a', '--now=2026-10-23T06:00:00Z'],
            self::fleetResult(RunOutcome::Reported, RunOutcome::Failed),
        );

        self::assertSame(1, $code);
        self::assertCount(1, $calls);
        self::assertSame($this->cfg, $calls[0][0]);
        self::assertTrue($calls[0][1]->dryRun);
        self::assertTrue($calls[0][1]->estimate);
        self::assertSame(['api', 'bff-a'], $calls[0][2]);
        self::assertSame('2026-10-23T06:00:00+00:00', $calls[0][3]?->format(DATE_ATOM));
        self::assertStringContainsString('s0: outcome=reported', $out);
        self::assertStringContainsString('fleet platform: services=2 failed=1', $out);
    }

    public function testFleetRunAcceptsADirectory(): void
    {
        [$code, , , $calls] = $this->execFleet(['fleet:run', '--config=' . sys_get_temp_dir()], self::fleetResult(RunOutcome::Quiet));

        self::assertSame(0, $code);
        self::assertSame(sys_get_temp_dir(), $calls[0][0]);
    }

    public function testFleetRunJsonIsOneStdoutLineAndHumanTextGoesToStderr(): void
    {
        [$code, $out, $err] = $this->execFleet(['fleet:run', '--config=' . $this->cfg, '--json'], self::fleetResult(RunOutcome::Quiet));

        self::assertSame(0, $code);
        self::assertSame(1, substr_count($out, "\n"));
        $j = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('platform', $j['fleet']);
        self::assertStringContainsString('fleet platform: services=1', $err);
        self::assertStringNotContainsString('fleet platform', $out);
    }

    public function testFleetRunInvalidFleetIsExitTwoWithTheKeyPathOnStderr(): void
    {
        [$code, $out, $err] = $this->execFleet(['fleet:run', '--config=' . $this->cfg, '--json'], new InvalidConfig('services[1] (bff-a): selection.growth_factor must be greater than 1'));

        self::assertSame(2, $code);
        self::assertSame('{"fleet":null,"exit":2,"error":"invalid_config"}' . "\n", $out);
        self::assertStringContainsString('services[1] (bff-a): selection.growth_factor', $err);

        [$code, $out] = $this->execFleet(['fleet:run', '--config=' . $this->cfg], new InvalidConfig('x'));
        self::assertSame(2, $code);
        self::assertSame('', $out);
    }

    public function testFleetRunUsageErrors(): void
    {
        $ok = self::fleetResult(RunOutcome::Quiet);
        foreach ([['fleet:run'], ['fleet:run', '--config=/nonexistent'], ['fleet:run', '--config=' . $this->cfg, '--bogus'], ['fleet:run', '--config=' . $this->cfg, '--only=']] as $args) {
            [$code, , , $calls] = $this->execFleet($args, $ok);
            self::assertSame(2, $code, implode(' ', $args));
            self::assertSame([], $calls);
        }
        [$code, , $err] = $this->execFleet(['fleet:run', '--config=' . $this->cfg, '--estimate'], $ok);
        self::assertSame(2, $code);
        self::assertStringContainsString('--estimate requires --dry-run', $err);
        [$code, , $err] = $this->execFleet(['fleet:run', '--config=' . $this->cfg, '--now=yesterday'], $ok);
        self::assertSame(2, $code);
        self::assertStringContainsString('invalid --now', $err);
    }

    public function testFleetRunWithoutAFleetFactoryIsUsage(): void
    {
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        $code = (new Application(static function (): callable {
            throw new \LogicException('x');
        }, $err))->run(['watcher', 'fleet:run', '--config=' . $this->cfg]);

        self::assertSame(2, $code);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function execFleetOps(array $args, CheckReport|FleetStatus|InvalidConfig $outcome): array
    {
        $check = static function (string $path, bool $postTest) use ($outcome): CheckReport {
            if ($outcome instanceof InvalidConfig) {
                throw $outcome;
            }
            self::assertInstanceOf(CheckReport::class, $outcome);

            return $outcome;
        };
        $status = static function (string $path) use ($outcome): FleetStatus {
            if ($outcome instanceof InvalidConfig) {
                throw $outcome;
            }
            self::assertInstanceOf(FleetStatus::class, $outcome);

            return $outcome;
        };
        $err = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        self::assertIsResource($out);
        $unused = static function (): callable {
            throw new \LogicException('not used');
        };
        $code = (new Application($unused, $err, $out, null, null, null, $check, $status))->run(['watcher', ...$args]);
        rewind($err);
        rewind($out);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    private static function fleetStatus(string $state): FleetStatus
    {
        $now = new DateTimeImmutable('2026-10-23T06:00:00+00:00');
        $one = new HeartbeatStatus($state, $state === 'unknown' ? null : ['outcome' => 'quiet'], 60, 3600, $now, $now, $now);

        return new FleetStatus('platform', $one, ['api' => $one]);
    }

    public function testFleetCheckPrintsTheReportAndMapsTheExitCode(): void
    {
        [$code, $out] = $this->execFleetOps(['fleet:check', '--config=' . $this->cfg, '--post-test'], new CheckReport([CheckResult::pass('order', 'api'), CheckResult::fail('api/source', 'HTTP 502')]));

        self::assertSame(1, $code);
        self::assertStringContainsString('api/source', $out);
    }

    public function testFleetCheckInvalidFleetIsAFailedConfigCheck(): void
    {
        [$code, $out] = $this->execFleetOps(['fleet:check', '--config=' . $this->cfg], new InvalidConfig('services[1] (bff-a): bad'));

        self::assertSame(1, $code);
        self::assertStringContainsString('services[1] (bff-a): bad', $out);
    }

    public function testFleetStatusTextJsonAndExitCodes(): void
    {
        [$code, $out] = $this->execFleetOps(['fleet:status', '--config=' . $this->cfg], self::fleetStatus('ok'));
        self::assertSame(0, $code);
        self::assertStringContainsString('fleet:          platform', $out);

        [$code, $out, $err] = $this->execFleetOps(['fleet:status', '--config=' . $this->cfg, '--json'], self::fleetStatus('stale'));
        self::assertSame(1, $code);
        self::assertCount(1, explode("\n", trim($out)));
        self::assertSame('platform', json_decode($out, true)['fleet']);
        self::assertStringContainsString('fleet:          platform', $err);

        [$code] = $this->execFleetOps(['fleet:status', '--config=' . $this->cfg], self::fleetStatus('unknown'));
        self::assertSame(3, $code);

        [$code, , $err] = $this->execFleetOps(['fleet:status', '--config=' . $this->cfg], new InvalidConfig('x'));
        self::assertSame(2, $code);
        self::assertStringContainsString('invalid fleet: x', $err);
    }

    public function testFleetOpsRejectUnknownFlagsAndWantAConfig(): void
    {
        [$code] = $this->execFleetOps(['fleet:status', '--config=' . $this->cfg, '--post-test'], self::fleetStatus('ok'));
        self::assertSame(2, $code);
        [$code] = $this->execFleetOps(['fleet:check'], self::fleetStatus('ok'));
        self::assertSame(2, $code);
    }
}
