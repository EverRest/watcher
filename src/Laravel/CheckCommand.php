<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Closure;
use Illuminate\Console\Command;
use Watcher\Config\InvalidConfig;
use Watcher\Operate\CheckReport;
use Watcher\Operate\Doctor;

final class CheckCommand extends Command
{
    protected $signature = 'watcher:check {--post-test : Also post one labelled test message per destination} {--json : One JSON line on stdout, the report on stderr}';

    protected $description = 'Check the watcher setup (source, provider, models, state, notifier) before the first run';

    /** @param Closure(bool): CheckReport $factory takes --post-test; throws InvalidConfig when the config cannot be loaded */
    public function __construct(private readonly Closure $factory)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $report = ($this->factory)((bool) $this->option('post-test'));
        } catch (InvalidConfig $e) {
            $report = Doctor::configFailure('invalid: ' . $e->getMessage());
        }

        $json = (bool) $this->option('json');
        $text = rtrim($report->text(), "\n");
        if ($json) {
            $this->output->getErrorStyle()->writeln($text);
            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), null, \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);
        } else {
            $this->line($text, null, \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);
        }

        return $report->exitCode();
    }
}
