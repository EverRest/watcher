<?php

declare(strict_types=1);

// Child process of FatalGuardWiringTest: a run whose source exhausts memory. Prints the host-log line and whether the lock was freed.
use Psr\Log\AbstractLogger;
use Watcher\Schedule\Window;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\StreamSelector;
use Watcher\Tests\EndToEnd\Harness;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$h = new Harness();
$h->hostLog = new class () extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        echo 'LOG ', $message, "\n";
    }
};
$source = new class ($h) implements LogSource {
    public function __construct(private readonly Harness $h)
    {
    }

    public function totals(Window $window, StreamSelector $selector): array
    {
        return ['error' => 5];
    }

    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): Generator
    {
        $h = $this->h;
        // Registered after the guard armed itself, so it runs after the guard's handler.
        register_shutdown_function(static function () use ($h): void {
            echo 'LOCK ', $h->state->acquire($h->lockKey(), 'probe', 60) ? 'released' : 'held', "\n";
        });
        $x = [];
        while (true) {
            $x[] = str_repeat('x', 1_000_000);
        }
        yield;
    }

    public function lastReadTruncated(): bool
    {
        return false;
    }

    public function versions(Window $window, StreamSelector $selector, string $label): array
    {
        return [];
    }
};
$h->watcher($source)->run();
