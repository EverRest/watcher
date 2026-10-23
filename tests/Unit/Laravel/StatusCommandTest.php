<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Laravel;

use Closure;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Watcher\Config\InvalidConfig;
use Watcher\Laravel\StatusCommand;
use Watcher\Operate\HeartbeatStatus;

final class StatusCommandTest extends TestCase
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
        $cmd = new StatusCommand($factory, 'svc');
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

    private static function hb(string $state): HeartbeatStatus
    {
        $now = new DateTimeImmutable('2026-10-24T09:12:55+00:00');
        $ok = new DateTimeImmutable('2026-10-24T06:00:41+00:00');

        return new HeartbeatStatus($state, ['outcome' => 'quiet', 'finishedAt' => $ok->format(DATE_ATOM)], 11534, 129600, $now, $ok, null);
    }

    public function testExitCodesPerStateAndBothInstantsPrinted(): void
    {
        foreach (['ok' => 0, 'stale' => 1, 'failed' => 1, 'unknown' => 3] as $state => $expected) {
            [$code, $out] = $this->exec(static fn (): HeartbeatStatus => self::hb($state));
            self::assertSame($expected, $code, $state);
            self::assertStringContainsString("state:          $state", $out);
            self::assertStringContainsString('compared:       now=2026-10-24T09:12:55+00:00 against last ok 2026-10-24T06:00:41+00:00', $out);
        }
    }

    public function testJsonIsOneStdoutLine(): void
    {
        [$code, $out, $err] = $this->exec(static fn (): HeartbeatStatus => self::hb('ok'), ['--json' => true]);

        self::assertSame(0, $code);
        self::assertSame(1, substr_count($out, "\n"));
        $j = json_decode($out, true);
        self::assertIsArray($j);
        self::assertSame('svc', $j['service']);
        self::assertSame('ok', $j['state']);
        self::assertStringContainsString('state:', $err);
    }

    public function testInvalidConfigIsExitTwo(): void
    {
        [$code, $out] = $this->exec(static function (): HeartbeatStatus {
            throw new InvalidConfig('state.driver is required');
        });

        self::assertSame(2, $code);
        self::assertStringContainsString('invalid config: state.driver is required', $out);
    }
}
