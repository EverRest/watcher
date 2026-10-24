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
use Watcher\Fleet\FleetResult;
use Watcher\Fleet\ServiceResult;
use Watcher\Laravel\FleetCommand;
use Watcher\Operate\RunOptions;
use Watcher\RunOutcome;
use Watcher\RunResult;

final class FleetCommandTest extends TestCase
{
    private string $stderr = '';

    protected function setUp(): void
    {
        if (!class_exists(Command::class) || !class_exists(Container::class)) {
            self::markTestSkipped('illuminate/console and illuminate/container are not installed.');
        }
    }

    /**
     * @param array<string, string|bool> $input
     * @return array{int, string}
     */
    private function exec(Closure $factory, array $input = []): array
    {
        $cmd = new FleetCommand($factory);
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

    private static function fleetOf(RunOutcome ...$outcomes): FleetResult
    {
        $services = [];
        foreach ($outcomes as $i => $o) {
            $services[] = ServiceResult::ran('s' . $i, new RunResult($o));
        }

        return new FleetResult('platform', $services);
    }

    public function testExitCodeIsTheFleetsWorstAndTheLinesAreShown(): void
    {
        [$code, $out] = $this->exec(static fn (): callable => static fn (): FleetResult => self::fleetOf(RunOutcome::Reported, RunOutcome::Failed));

        self::assertSame(1, $code);
        self::assertStringContainsString('s0: outcome=reported', $out);
        self::assertStringContainsString('fleet platform: services=2 failed=1', $out);

        [$code] = $this->exec(static fn (): callable => static fn (): FleetResult => self::fleetOf(RunOutcome::Quiet));
        self::assertSame(0, $code);
    }

    public function testNowOnlyAndOptionsReachTheFactory(): void
    {
        $seenOptions = $seenNow = null;
        $seenOnly = [];
        $factory = static function (RunOptions $o, callable $out, array $only) use (&$seenOptions, &$seenOnly, &$seenNow): callable {
            return static function (?DateTimeImmutable $now) use ($o, $only, &$seenOptions, &$seenOnly, &$seenNow): FleetResult {
                [$seenOptions, $seenOnly, $seenNow] = [$o, $only, $now];

                return self::fleetOf(RunOutcome::Quiet);
            };
        };

        $this->exec($factory, ['--now' => '2026-10-23T06:00:00+02:00', '--only' => 'api, bff-a', '--dry-run' => true, '--estimate' => true]);

        self::assertEquals(RunOptions::dryRun(true), $seenOptions);
        self::assertSame(['api', 'bff-a'], $seenOnly);
        self::assertSame('2026-10-23T06:00:00+02:00', $seenNow?->format(DATE_ATOM));
    }

    public function testJsonIsOneLineOnStdoutAndTextOnStderr(): void
    {
        [$code, $out] = $this->exec(static fn (): callable => static fn (): FleetResult => self::fleetOf(RunOutcome::Quiet), ['--json' => true]);

        self::assertSame(0, $code);
        self::assertSame(1, substr_count($out, "\n"));
        self::assertSame('platform', json_decode($out, true, 512, JSON_THROW_ON_ERROR)['fleet']);
        self::assertStringContainsString('fleet platform: services=1', $this->stderr);
    }

    public function testInvalidFleetIsExitTwo(): void
    {
        [$code, $out] = $this->exec(static function (): callable {
            throw new InvalidConfig('services[0]: service is required');
        }, ['--json' => true]);

        self::assertSame(2, $code);
        self::assertSame('{"fleet":null,"exit":2,"error":"invalid_config"}' . "\n", $out);
        self::assertStringContainsString('invalid fleet: services[0]: service is required', $this->stderr);
    }

    public function testBadNowAndEstimateWithoutDryRunAreExitTwoWithoutBuilding(): void
    {
        $built = false;
        $factory = static function () use (&$built): callable {
            $built = true;

            return static fn (): FleetResult => self::fleetOf(RunOutcome::Quiet);
        };

        self::assertSame(2, $this->exec($factory, ['--now' => 'soon'])[0]);
        self::assertSame(2, $this->exec($factory, ['--estimate' => true])[0]);
        self::assertFalse($built);
    }
}
