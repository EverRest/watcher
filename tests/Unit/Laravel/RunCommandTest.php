<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Laravel;

use Closure;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Watcher\Config\InvalidConfig;
use Watcher\Laravel\RunCommand;
use Watcher\Laravel\WatcherServiceProvider;
use Watcher\RunOutcome;
use Watcher\RunResult;
use Watcher\Watcher;

final class RunCommandTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Command::class) || !class_exists(Container::class)) {
            self::markTestSkipped('illuminate/console and illuminate/container are not installed.');
        }
    }

    /**
     * @param array<string, string> $input
     * @return array{int, string}
     */
    private function exec(Closure $factory, array $input = []): array
    {
        $cmd = new RunCommand($factory);
        $cmd->setLaravel(new class () extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        });
        $out = new BufferedOutput();
        $code = $cmd->run(new ArrayInput($input), $out);

        return [$code, $out->fetch()];
    }

    public function testExitCodeMapsFromRunResult(): void
    {
        foreach ([[RunOutcome::Reported, 0], [RunOutcome::Failed, 1]] as [$outcome, $expected]) {
            $calls = 0;
            [$code] = $this->exec(static function () use ($outcome, &$calls): callable {
                return static function () use ($outcome, &$calls): RunResult {
                    $calls++;

                    return new RunResult($outcome);
                };
            });
            self::assertSame($expected, $code);
            self::assertSame(1, $calls);
        }
    }

    public function testPrintsTheSummaryLine(): void
    {
        [$code, $out] = $this->exec(static fn (): callable => static fn (): RunResult => new RunResult(RunOutcome::Failed, 'source failed: loki 401 Unauthorized'));

        self::assertSame(1, $code);
        self::assertStringContainsString('watcher: outcome=failed reason="source failed: loki 401 Unauthorized"', $out);
    }

    public function testNowOptionIsPassedThrough(): void
    {
        $seen = 'unset';
        $factory = static function () use (&$seen): callable {
            return static function (?DateTimeImmutable $now) use (&$seen): RunResult {
                $seen = $now;

                return new RunResult(RunOutcome::Quiet);
            };
        };
        $this->exec($factory, ['--now' => '2026-10-23T06:00:00+02:00']);
        self::assertInstanceOf(DateTimeImmutable::class, $seen);
        self::assertSame('2026-10-23T06:00:00+02:00', $seen->format(DATE_ATOM));

        $this->exec($factory);
        self::assertNull($seen);
    }

    public function testInvalidNowReturnsTwoWithoutBuildingWatcher(): void
    {
        $built = false;
        [$code, $out] = $this->exec(static function () use (&$built): callable {
            $built = true;

            return static fn (): RunResult => new RunResult(RunOutcome::Quiet);
        }, ['--now' => 'soon']);
        self::assertSame(2, $code);
        self::assertFalse($built);
        self::assertStringContainsString('--now', $out);
    }

    public function testInvalidConfigReturnsTwoNamingTheKey(): void
    {
        [$code, $out] = $this->exec(static function (): callable {
            throw new InvalidConfig('state.driver is required');
        });
        self::assertSame(2, $code);
        self::assertStringContainsString('invalid config: state.driver is required', $out);
    }

    public function testProviderMergesPublishedConfigUnderWatcherKey(): void
    {
        if (!class_exists(\PhpOption\Option::class)) {
            self::markTestSkipped('env() in illuminate/support needs phpoption/phpoption, which is not installed.');
        }
        $app = new Container();
        $app->instance('config', new class () {
            /** @var array<string, mixed> */
            public array $items = ['watcher' => ['service' => 'host-override']];

            public function get(string $k, mixed $d = null): mixed
            {
                return $this->items[$k] ?? $d;
            }

            public function set(string $k, mixed $v): void
            {
                $this->items[$k] = $v;
            }
        });
        // @phpstan-ignore-next-line the provider only needs the container surface used by mergeConfigFrom
        (new WatcherServiceProvider($app))->register();

        $cfg = $app->make('config')->items['watcher'];
        self::assertSame('host-override', $cfg['service']);
        self::assertArrayHasKey('analysis', $cfg);
        self::assertArrayHasKey('notify', $cfg);
        self::assertTrue($app->bound(Watcher::class));
    }

    public function testPublishableConfigHasNoConsumerNamesOrModelIds(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../../config/watcher.php');
        self::assertDoesNotMatchRegularExpression('/orders-api|tenant-a|example|claude-(opus|sonnet|haiku)/i', $src);
    }
}
