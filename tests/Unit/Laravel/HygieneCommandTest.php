<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Laravel;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Watcher\Config\InvalidConfig;
use Watcher\Hygiene\HygieneOptions;
use Watcher\Hygiene\HygieneResult;
use Watcher\Hygiene\Report;
use Watcher\Laravel\HygieneCommand;

final class HygieneCommandTest extends TestCase
{
    private string $stderr = '';

    /** @var list<HygieneOptions> */
    private array $seen = [];

    protected function setUp(): void
    {
        if (!class_exists(Command::class) || !class_exists(Container::class)) {
            self::markTestSkipped('illuminate/console and illuminate/container are not installed.');
        }
    }

    private static function report(): Report
    {
        return new class () implements Report {
            public function name(): string
            {
                return 'cost';
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
                return 'cost: body';
            }
        };
    }

    /**
     * @param array<string, string|bool> $input
     * @return array{int, string}
     */
    private function exec(array $input = [], HygieneResult|InvalidConfig|null $outcome = null): array
    {
        $this->seen = [];
        $factory = function (HygieneOptions $o) use ($outcome): HygieneResult {
            $this->seen[] = $o;
            if ($outcome instanceof InvalidConfig) {
                throw $outcome;
            }

            return $outcome ?? new HygieneResult(['cost' => self::report()]);
        };
        $cmd = new HygieneCommand(Closure::fromCallable($factory));
        $cmd->setLaravel(new class () extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        });
        $out = new SplitOutput();
        $code = $cmd->run(new ArrayInput($input), $out);
        $this->stderr = $out->stderr();

        return [$code, $out->fetch()];
    }

    public function testItPrintsTheReportsAndPassesTheOptionsOn(): void
    {
        [$code, $out] = $this->exec(['--report' => 'privacy,cost', '--days' => '3', '--notify' => true, '--now' => '2026-10-08T06:00:00Z', '--decline' => 'abc']);

        self::assertSame(0, $code);
        self::assertStringContainsString('cost: body', $out);
        self::assertSame(['privacy', 'cost'], $this->seen[0]->reports);
        self::assertSame(3, $this->seen[0]->days);
        self::assertTrue($this->seen[0]->notify);
        self::assertSame('abc', $this->seen[0]->decline);
    }

    public function testDryRunReachesTheOptions(): void
    {
        $this->exec(['--dry-run' => true]);
        self::assertTrue($this->seen[0]->dryRun);

        $this->exec([]);
        self::assertFalse($this->seen[0]->dryRun);
    }

    public function testJsonIsOneLineOnStdoutAndTheTextGoesToStderr(): void
    {
        [$code, $out] = $this->exec(['--json' => true]);

        self::assertSame(0, $code);
        self::assertSame(1, json_decode($out, true)['schema']);
        self::assertStringContainsString('cost: body', $this->stderr);
    }

    public function testBadInputIsExitTwoBeforeAnyWork(): void
    {
        foreach ([['--report' => 'nope'], ['--days' => '99'], ['--now' => 'yesterday']] as $input) {
            [$code] = $this->exec($input);
            self::assertSame(2, $code);
        }
        self::assertSame([], $this->seen);
    }

    public function testInvalidConfigAndFailureCodes(): void
    {
        [$code, $out] = $this->exec([], new InvalidConfig('hygiene.event_cap must be a positive integer'));
        self::assertSame(2, $code);
        self::assertStringContainsString('invalid config: hygiene.event_cap', $out);

        [$code] = $this->exec([], new HygieneResult(exit: 1, failure: 'source unavailable'));
        self::assertSame(1, $code);
    }

    public function testDisabledAndSkippedHintsGoToStderr(): void
    {
        $this->exec([], new HygieneResult(disabled: true));
        self::assertStringContainsString('disabled', $this->stderr);

        $this->exec([], new HygieneResult(['cost' => self::report()], skipped: true));
        self::assertStringContainsString('hygiene reports disabled', $this->stderr);
    }
}
