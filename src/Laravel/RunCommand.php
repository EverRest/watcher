<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Closure;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Watcher\Cli\Application;
use Watcher\Config\InvalidConfig;
use Watcher\RunResult;

final class RunCommand extends Command
{
    protected $signature = 'watcher:run {--now= : ISO-8601 instant to run as (default: now)}';

    protected $description = 'Run the log watcher (the package decides whether a run is due)';

    /** @param Closure(): (callable(?DateTimeImmutable): RunResult) $factory throws InvalidConfig before any external call */
    public function __construct(private readonly Closure $factory)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $raw = $this->option('now');
        $now = null;
        if (is_string($raw) && $raw !== '' && ($now = Application::parseNow($raw)) === null) {
            $this->error('invalid --now: expected ISO-8601, e.g. 2026-10-23T06:00:00Z');

            return 2;
        }

        try {
            $result = ($this->factory)()($now);
            $this->line('watcher: ' . $result->summary());

            return $result->exitCode();
        } catch (InvalidConfig $e) {
            $this->error('invalid config: ' . $e->getMessage());

            return 2;
        }
    }
}
