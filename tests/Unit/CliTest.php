<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Cli\Application;
use Watcher\Config\InvalidConfig;
use Watcher\RunOutcome;
use Watcher\RunResult;

final class CliTest extends TestCase
{
    private string $cfg;

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
        $factory = static function (array $config) use ($outcome, &$calls): callable {
            if ($outcome instanceof InvalidConfig) {
                throw $outcome;
            }

            return static function (?DateTimeImmutable $now) use ($config, $outcome, &$calls): RunResult {
                $calls[] = [$config, $now];

                return $outcome;
            };
        };
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        $code = (new Application($factory, $err))->run(['watcher', ...$args]);
        rewind($err);

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
}
