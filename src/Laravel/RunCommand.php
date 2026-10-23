<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Closure;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Watcher\Cli\Application;
use Watcher\Config\InvalidConfig;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunOptions;
use Watcher\Operate\RunSummaryData;
use Watcher\RunOutcome;
use Watcher\RunResult;

final class RunCommand extends Command
{
    protected $signature = 'watcher:run {--now= : ISO-8601 instant to run as (default: now)} {--dry-run : Preview: nothing is posted or recorded} {--estimate : With --dry-run, estimate tokens and cost without model calls} {--json : One JSON summary line on stdout, human text on stderr}';

    protected $description = 'Run the log watcher (the package decides whether a run is due)';

    /** @param Closure(RunOptions, callable(string): mixed): (callable(?DateTimeImmutable): RunResult) $factory receives the sink for dry-run output; throws InvalidConfig before any external call */
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
        if ($this->option('estimate') && !$this->option('dry-run')) {
            $this->error('--estimate requires --dry-run');

            return 2;
        }

        $json = (bool) $this->option('json');
        // Under --json stdout carries the one JSON line only.
        $human = $json ? $this->output->getErrorStyle() : $this->output;
        $options = new RunOptions((bool) $this->option('dry-run'), (bool) $this->option('estimate'), $json);

        try {
            $result = ($this->factory)($options, static function (string $text) use ($human): void {
                $human->write($text);
            })($now);
        } catch (InvalidConfig $e) {
            $human->writeln('invalid config: ' . $e->getMessage());
            if ($json) {
                $this->line((new JsonSummary())->render(new RunSummaryData(RunOutcome::InvalidConfig, null, 'invalid_config')));
            }

            return 2;
        }

        $human->writeln('watcher: ' . $result->summary());
        if ($json) {
            $this->line((new JsonSummary())->render($result->summaryData ?? new RunSummaryData($result->outcome, null, $result->failureReason)));
        }

        return $result->exitCode();
    }
}
