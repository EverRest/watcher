<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Watcher\Config\InvalidConfig;

/** Hourly host health check: reads the host's snapshot and alerts on version changes. Never part of the daily run. */
final class HealthCommand extends Command
{
    protected $signature = 'watcher:health';

    protected $description = 'Check the host health snapshot hourly and alert when a service version changes';

    /** @param Closure(DateTimeImmutable): int $check returns the exit code; a disabled check returns 0 */
    public function __construct(private readonly Closure $check)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return ($this->check)(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        } catch (InvalidConfig $e) {
            $this->error('invalid config: ' . $e->getMessage());

            return 2;
        } catch (\Throwable $e) {
            // The connection is opened here: its error text is sanitised by the factory; the class alone is printed.
            $this->error('health: ' . $e::class);

            return 1;
        }
    }
}
