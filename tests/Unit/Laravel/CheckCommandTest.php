<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Laravel;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Watcher\Config\InvalidConfig;
use Watcher\Laravel\CheckCommand;
use Watcher\Operate\CheckReport;
use Watcher\Operate\CheckResult;

final class CheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Command::class) || !class_exists(Container::class)) {
            self::markTestSkipped('illuminate/console and illuminate/container are not installed.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{int, string, string}
     */
    private function exec(Closure $factory, array $input = []): array
    {
        $cmd = new CheckCommand($factory);
        $cmd->setLaravel(new class () extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        });
        $out = new SplitOutput();
        $code = $cmd->run(new ArrayInput($input), $out);

        return [$code, $out->fetch(), $out->stderr()];
    }

    public function testPrintsTheReportAndExitsWithItsCode(): void
    {
        $bad = new CheckReport([CheckResult::pass('config'), CheckResult::fail('source', 'unauthorised')]);
        $seen = null;
        [$code, $out, $err] = $this->exec(static function (bool $post) use ($bad, &$seen): CheckReport {
            $seen = $post;

            return $bad;
        });

        self::assertSame(1, $code);
        self::assertFalse($seen);
        self::assertStringContainsString('source         FAIL  unauthorised', $out);
        self::assertSame('', $err);
    }

    public function testPostTestIsPassedThrough(): void
    {
        $seen = null;
        [$code] = $this->exec(static function (bool $post) use (&$seen): CheckReport {
            $seen = $post;

            return new CheckReport([CheckResult::pass('config')]);
        }, ['--post-test' => true]);

        self::assertSame(0, $code);
        self::assertTrue($seen);
    }

    public function testJsonIsOneStdoutLineAndTextGoesToStderr(): void
    {
        $r = new CheckReport([CheckResult::pass('config'), CheckResult::fail('state', 'write failed')]);
        [$code, $out, $err] = $this->exec(static fn (): CheckReport => $r, ['--json' => true]);

        self::assertSame(1, $code);
        self::assertSame(1, substr_count($out, "\n"));
        self::assertSame($r->toArray(), json_decode($out, true));
        self::assertStringContainsString('state', $err);
        self::assertStringContainsString('result: 1 failed', $err);
    }

    public function testInvalidConfigIsAFailedConfigCheckWithExitOne(): void
    {
        [$code, $out] = $this->exec(static function (): CheckReport {
            throw new InvalidConfig('state.driver is required');
        });

        self::assertSame(1, $code);
        self::assertStringContainsString('invalid: state.driver is required', $out);
        self::assertStringContainsString('SKIP', $out);
    }
}
