<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
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
        $this->app->bind(
            RunCommand::class,
            static fn ($app): RunCommand => new RunCommand(static fn (): callable => $app->make(Watcher::class)->run(...)),
        );
    }

    public function boot(): void
    {
        $this->commands([RunCommand::class]);
        if (method_exists($this->app, 'basePath')) {
            $this->publishes([self::CONFIG => $this->app->basePath('config/watcher.php')], 'watcher-config');
        }
    }
}
