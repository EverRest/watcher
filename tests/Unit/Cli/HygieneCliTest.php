<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Watcher\Cli\Application;
use Watcher\Config\InvalidConfig;
use Watcher\Hygiene\HygieneOptions;
use Watcher\Hygiene\HygieneResult;
use Watcher\Hygiene\Report;

final class HygieneCliTest extends TestCase
{
    private string $cfg;

    /** @var list<HygieneOptions> */
    private array $options = [];

    protected function setUp(): void
    {
        $this->cfg = sys_get_temp_dir() . '/watcher-hyg-cli-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->cfg, '<?php return ["service" => "svc"];');
    }

    protected function tearDown(): void
    {
        @unlink($this->cfg);
    }

    private static function report(): Report
    {
        return new class () implements Report {
            public function name(): string
            {
                return 'proposals';
            }

            public function isEmpty(): bool
            {
                return false;
            }

            public function toArray(): array
            {
                return ['total' => 1];
            }

            public function text(bool $notice = false): string
            {
                return 'proposals: body';
            }
        };
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function exec(array $args, HygieneResult|InvalidConfig|null $outcome = null, bool $wired = true): array
    {
        $this->options = [];
        $factory = $wired ? function (array $config, HygieneOptions $o) use ($outcome): HygieneResult {
            $this->options[] = $o;
            if ($outcome instanceof InvalidConfig) {
                throw $outcome;
            }

            return $outcome ?? new HygieneResult(['proposals' => self::report()]);
        } : null;
        $err = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        self::assertIsResource($out);
        $app = new Application(static fn (array $c, \Watcher\Operate\RunOptions $o): callable => throw new \LogicException('not used'), $err, $out, hygieneFactory: $factory);
        $code = $app->run(['watcher', ...$args]);
        rewind($err);
        rewind($out);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testUsageNamesTheCommand(): void
    {
        [$code, , $err] = $this->exec([]);

        self::assertSame(2, $code);
        self::assertStringContainsString('watcher hygiene --config=', $err);
    }

    public function testDefaultsAskForAllFourReportsAndPrintTheText(): void
    {
        [$code, $out] = $this->exec(['hygiene', '--config=' . $this->cfg]);

        self::assertSame(0, $code);
        self::assertSame("proposals: body\n", $out);
        self::assertEquals(new HygieneOptions(), $this->options[0]);
    }

    public function testOptionsReachTheFactory(): void
    {
        $this->exec(['hygiene', '--config=' . $this->cfg, '--report=privacy,proposals', '--notify', '--now=2026-10-08T06:00:00Z']);

        $o = $this->options[0];
        self::assertSame(['privacy', 'proposals'], $o->reports);
        self::assertTrue($o->notify);
        self::assertSame('2026-10-08T06:00:00+00:00', $o->now?->format(DATE_ATOM));
    }

    public function testDryRunReachesTheFactory(): void
    {
        $this->exec(['hygiene', '--config=' . $this->cfg, '--dry-run']);

        self::assertTrue($this->options[0]->dryRun);
        $this->exec(['hygiene', '--config=' . $this->cfg]);
        self::assertFalse($this->options[0]->dryRun);
    }

    public function testDeclineReachesTheFactory(): void
    {
        $this->exec(['hygiene', '--config=' . $this->cfg, '--decline=a1b2c3d4e5']);

        self::assertSame('a1b2c3d4e5', $this->options[0]->decline);
    }

    public function testJsonPutsOneLineOnStdoutAndTheTextOnStderr(): void
    {
        [$code, $out, $err] = $this->exec(['hygiene', '--config=' . $this->cfg, '--json']);

        self::assertSame(0, $code);
        self::assertStringContainsString('proposals: body', $err);
        self::assertSame(1, substr_count($out, "\n"));
        self::assertSame(1, json_decode($out, true)['schema']);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function badInput(): iterable
    {
        yield 'unknown report' => [['--report=privacy,nope'], 'unknown report'];
        yield 'empty report' => [['--report='], 'unknown report'];
        yield 'bad now' => [['--now=yesterday'], 'invalid --now'];
    }

    /** @param list<string> $extra */
    #[\PHPUnit\Framework\Attributes\DataProvider('badInput')]
    public function testBadInputIsAUsageErrorBeforeAnyWork(array $extra, string $message): void
    {
        [$code, , $err] = $this->exec(['hygiene', '--config=' . $this->cfg, ...$extra]);

        self::assertSame(2, $code);
        self::assertStringContainsString($message, $err);
        self::assertSame([], $this->options);
    }

    public function testAnUnknownFlagOrNoFactoryPrintsUsage(): void
    {
        self::assertSame(2, $this->exec(['hygiene', '--config=' . $this->cfg, '--fix'])[0]);
        self::assertSame(2, $this->exec(['hygiene', '--config=' . $this->cfg], wired: false)[0]);
        self::assertSame(2, $this->exec(['hygiene', '--estimate', '--config=' . $this->cfg])[0]);
    }

    public function testAnInvalidConfigExitsTwoNamingTheSetting(): void
    {
        [$code, , $err] = $this->exec(['hygiene', '--config=' . $this->cfg], new InvalidConfig('hygiene.event_cap must be a positive integer'));

        self::assertSame(2, $code);
        self::assertStringContainsString('invalid config: hygiene.event_cap', $err);
    }

    public function testExitCodesAndHintsPassThrough(): void
    {
        self::assertSame(1, $this->exec(['hygiene', '--config=' . $this->cfg], new HygieneResult(exit: 1, failure: 'source unavailable'))[0]);

        [$code, , $err] = $this->exec(['hygiene', '--config=' . $this->cfg], new HygieneResult(disabled: true));
        self::assertSame(0, $code);
        self::assertStringContainsString(Application::DISABLED_HINT, $err);

        [$code, , $err] = $this->exec(['hygiene', '--config=' . $this->cfg], new HygieneResult(['proposals' => self::report()], skipped: true));
        self::assertSame(0, $code);
        self::assertStringContainsString('hygiene reports disabled (set hygiene.enabled=true)', $err);
    }
}
