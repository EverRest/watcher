<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Laravel;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Watcher\Config\InvalidConfig;
use Watcher\Laravel\StateGetCommand;

final class StateGetCommandTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Command::class) || !class_exists(Container::class)) {
            self::markTestSkipped('illuminate/console and illuminate/container are not installed.');
        }
    }

    /** @return array{int, string, string} */
    private function exec(Closure $factory, string $key = 'heartbeat'): array
    {
        $cmd = new StateGetCommand($factory);
        $cmd->setLaravel(new class () extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        });
        $out = new SplitOutput();
        $code = $cmd->run(new ArrayInput(['key' => $key]), $out);

        return [$code, $out->fetch(), $out->stderr()];
    }

    public function testPrintsTheValue(): void
    {
        $seen = null;
        [$code, $out] = $this->exec(static function (string $spec) use (&$seen): string {
            $seen = $spec;

            return '{"a":1}';
        }, 'run:2026-10-24T06:00:00Z');

        self::assertSame(0, $code);
        self::assertSame('run:2026-10-24T06:00:00Z', $seen);
        self::assertStringContainsString('{"a":1}', $out);
    }

    public function testMissingIsOneRefusedAndInvalidConfigAreTwo(): void
    {
        self::assertSame(1, $this->exec(static fn (): ?string => null)[0]);
        self::assertSame(2, $this->exec(static fn () => throw new InvalidArgumentException('state:get: not a logical key'))[0]);
        self::assertSame(2, $this->exec(static fn () => throw new InvalidConfig('x'))[0]);
    }
}
