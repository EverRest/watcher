<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Closure;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Watcher\Cli\Application;
use Watcher\Config\InvalidConfig;
use Watcher\Fleet\FleetResult;
use Watcher\Operate\RunOptions;

final class FleetCommand extends Command
{
    protected $signature = 'watcher:fleet {--now= : ISO-8601 instant to run as (default: now)} {--only= : Comma-separated services to run (default: all)} {--dry-run : Preview: nothing is posted or recorded} {--estimate : With --dry-run, estimate tokens without model calls} {--json : One JSON summary line on stdout, human text on stderr}';

    protected $description = 'Run every service of the configured fleet (watcher.fleet), each in isolation';

    /** @param Closure(RunOptions, callable(string): mixed, list<string>): (callable(?DateTimeImmutable): FleetResult) $factory throws InvalidConfig before any external call */
    public function __construct(private readonly Closure $factory)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $raw = $this->option('now');
        $now = null;
        if (is_string($raw) && $raw !== '' && ($now = Application::parseNow($raw)) === null) {
            $this->error('invalid --now: expected ISO-8601, e.g. 2026-10-23T06:00:00Z', \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);

            return 2;
        }
        if ($this->option('estimate') && !$this->option('dry-run')) {
            $this->error('--estimate requires --dry-run', \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);

            return 2;
        }
        $onlyRaw = $this->option('only');
        $only = is_string($onlyRaw) ? Application::parseOnly($onlyRaw) : [];

        $json = (bool) $this->option('json');
        $human = $json ? $this->output->getErrorStyle() : $this->output;
        $options = new RunOptions((bool) $this->option('dry-run'), (bool) $this->option('estimate'), $json);

        try {
            $result = ($this->factory)($options, static function (string $text) use ($human): void {
                $human->write($text);
            }, $only)($now);
        } catch (InvalidConfig $e) {
            $human->writeln('invalid fleet: ' . $e->getMessage());
            if ($json) {
                $this->line(FleetResult::invalidJson(), null, \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);
            }

            return 2;
        }

        foreach ($result->lines() as $line) {
            $human->writeln($line);
        }
        if ($json) {
            $this->line($result->toJson(), null, \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);
        }

        return $result->exitCode();
    }
}
