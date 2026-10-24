<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Watcher\Laravel\WatcherServiceProvider;
use Watcher\Watcher;

final class WatcherServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Container::class) || !class_exists(ServiceProvider::class)) {
            self::markTestSkipped('illuminate/container and illuminate/support are not installed.');
        }
    }

    private function app(): Container
    {
        $app = new Container();
        $app->instance('config', new class () {
            /** @return array<string, mixed> */
            public function get(string $key): array
            {
                return [
                    'service' => 'svc',
                    'schedule' => ['run_at' => '06:00'],
                    'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid', 'auth' => ['type' => 'bearer', 'token' => 't']]],
                    'analysis' => ['api_key' => 'k', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], 'prices' => ['a' => [1, 5, 0.1, 1.25], 'b' => [3, 15, 0.3, 3.75], 'c' => [5, 25, 0.5, 6.25]]],
                    'state' => ['driver' => 'file', 'file' => ['path' => sys_get_temp_dir() . '/watcher-provider-test']],
                    'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
                ];
            }

            public function set(string $key, mixed $value): void
            {
            }
        });
        /** @var Application $application the provider type-hints the Application contract; the container satisfies its API */
        $application = $app;
        (new WatcherServiceProvider($application))->register();

        return $app;
    }

    public function testPassesTheBoundLoggerToTheWatcher(): void
    {
        $logger = new NullLogger();
        $app = $this->app();
        $app->instance(LoggerInterface::class, $logger);

        $watcher = $app->make(Watcher::class);

        self::assertSame($logger, (new ReflectionProperty(Watcher::class, 'hostLog'))->getValue($watcher));
    }

    public function testWithoutABoundLoggerTheWatcherHasNone(): void
    {
        $watcher = $this->app()->make(Watcher::class);

        self::assertNull((new ReflectionProperty(Watcher::class, 'hostLog'))->getValue($watcher));
    }

    public function testCheckAndStatusCommandsAreBound(): void
    {
        $app = $this->app();

        self::assertInstanceOf(\Watcher\Laravel\CheckCommand::class, $app->make(\Watcher\Laravel\CheckCommand::class));
        self::assertInstanceOf(\Watcher\Laravel\StatusCommand::class, $app->make(\Watcher\Laravel\StatusCommand::class));
        self::assertInstanceOf(\Watcher\Laravel\RunCommand::class, $app->make(\Watcher\Laravel\RunCommand::class));
        self::assertInstanceOf(\Watcher\Laravel\FleetCommand::class, $app->make(\Watcher\Laravel\FleetCommand::class));
    }
}
