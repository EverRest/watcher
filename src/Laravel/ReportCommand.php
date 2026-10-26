<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Closure;
use Illuminate\Console\Command;
use Watcher\Cli\Application;
use Watcher\Config\InvalidConfig;
use Watcher\Report\EventLogException;
use Watcher\Report\ReportRunResult;
use Watcher\Report\Week;

final class ReportCommand extends Command
{
    protected $signature = 'watcher:report {--weekly : Build and send the weekly incident report} {--week= : ISO week YYYY-Www} {--dry-run : Print only; send and store nothing} {--json : One JSON line on stdout, human text on stderr}';

    protected $description = 'Weekly report from observe.file events (spec 005)';

    /** @param Closure(?Week, bool): ReportRunResult $factory */
    public function __construct(private readonly Closure $factory)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!$this->option('weekly')) {
            $this->error('report needs --weekly');

            return 2;
        }
        $week = null;
        $raw = $this->option('week');
        if (is_string($raw) && $raw !== '') {
            try {
                $week = Week::fromIso($raw);
            } catch (\InvalidArgumentException) {
                $this->error('invalid --week: expected YYYY-Www');

                return 2;
            }
        }
        try {
            $result = ($this->factory)($week, (bool) $this->option('dry-run'));
        } catch (InvalidConfig $e) {
            $this->error('invalid config: ' . $e->getMessage());

            return 2;
        } catch (EventLogException $e) {
            $this->error($e->getMessage());

            return 1;
        }
        if ($result->outcome === ReportRunResult::OUTCOME_DISABLED) {
            $this->error(Application::DISABLED_HINT);
        }
        $text = $result->text ?? ($result->outcome === ReportRunResult::OUTCOME_ALREADY ? 'report already sent' : '');
        if ($text !== '') {
            if ($this->option('json')) {
                $this->output->getErrorStyle()->writeln($text);
            } else {
                $this->line($text);
            }
        }
        if ($this->option('json') && $result->data !== null) {
            $this->line(json_encode($result->data->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        }

        return $result->exitCode();
    }
}
