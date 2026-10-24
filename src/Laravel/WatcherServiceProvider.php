<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Watcher\Config\Factory;
use Watcher\Fleet\FleetRunner;
use Watcher\Operate\CheckReport;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\RunOptions;
use Watcher\Watcher;

final class WatcherServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__ . '/../../config/watcher.php';

    public function register(): void
    {
        // Lumen only loads a config file once it is asked for.
        if (method_exists($this->app, 'configure')) {
            $this->app->configure('watcher');
        }
        $this->mergeConfigFrom(self::CONFIG, 'watcher');

        $this->app->singleton(Watcher::class, static function ($app): Watcher {
            $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

            return Watcher::fromConfig((array) $app->make('config')->get('watcher'), $logger);
        });
        // A real run reuses the singleton; a dry run builds its own Watcher with the dry-run collaborators.
        $this->app->bind(
            RunCommand::class,
            static fn ($app): RunCommand => new RunCommand(static function (RunOptions $options, callable $out) use ($app): callable {
                if (!$options->dryRun) {
                    return $app->make(Watcher::class)->run(...);
                }
                $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

                return Watcher::fromConfig((array) $app->make('config')->get('watcher'), $logger, null, $options, $out)->run(...);
            }),
        );
        // The fleet array lives under the framework config key watcher.fleet; a dry run's output goes to the sink.
        $this->app->bind(
            FleetCommand::class,
            static fn ($app): FleetCommand => new FleetCommand(static function (RunOptions $options, callable $out, array $only) use ($app): callable {
                $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;
                $runner = FleetRunner::fromConfig((array) $app->make('config')->get('watcher.fleet'), $logger, null, $out);

                return static fn (?\DateTimeImmutable $now): \Watcher\Fleet\FleetResult => $runner->run($now, $options, $only);
            }),
        );
        $this->app->bind(
            CheckCommand::class,
            static fn ($app): CheckCommand => new CheckCommand(static fn (bool $postTest): CheckReport => Factory::doctor((array) $app->make('config')->get('watcher'))->run($postTest)),
        );
        $this->app->bind(
            StatusCommand::class,
            static function ($app): StatusCommand {
                $config = (array) $app->make('config')->get('watcher');
                $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

                return new StatusCommand(
                    static fn (): HeartbeatStatus => Factory::heartbeatStatus($config, null, $logger),
                    is_string($config['service'] ?? null) ? $config['service'] : '',
                );
            },
        );
    }

    public function boot(): void
    {
        $this->commands([RunCommand::class, CheckCommand::class, StatusCommand::class, FleetCommand::class]);
        if (method_exists($this->app, 'basePath')) {
            $this->publishes([self::CONFIG => $this->app->basePath('config/watcher.php')], 'watcher-config');
        }
    }
}
