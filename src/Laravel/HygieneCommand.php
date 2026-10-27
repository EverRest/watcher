<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Closure;
use Illuminate\Console\Command;
use Watcher\Cli\Application;
use Watcher\Config\InvalidConfig;
use Watcher\Hygiene\HygieneOptions;
use Watcher\Hygiene\HygieneResult;

final class HygieneCommand extends Command
{
    protected $signature = 'watcher:hygiene {--report= : privacy,shape,proposals (default: all)} {--now= : ISO-8601 instant to report as} {--notify : Post each non-empty report to the notice destination} {--dry-run : Read-only: write nothing, take no lock, send nothing} {--decline= : Decline a rule proposal by id} {--json : One JSON line on stdout, human text on stderr}';

    protected $description = 'Log hygiene reports: personal data in logs, log shape, mute/watch proposals';

    /** @param Closure(HygieneOptions): HygieneResult $factory throws InvalidConfig before any external call */
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
        try {
            $options = HygieneOptions::fromInput(
                $this->string('report'),
                $this->string('decline'),
                $now,
                (bool) $this->option('notify'),
                (bool) $this->option('dry-run'),
            );
            $result = ($this->factory)($options);
        } catch (InvalidConfig $e) { // before InvalidArgumentException, which it extends
            $this->error('invalid config: ' . $e->getMessage(), \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);

            return 2;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);

            return 2;
        }

        $json = (bool) $this->option('json');
        $human = $json ? $this->output->getErrorStyle() : $this->output;
        if ($result->disabled) {
            $this->output->getErrorStyle()->writeln(Application::DISABLED_HINT);
        }
        if ($result->skipped) {
            $this->output->getErrorStyle()->writeln('watcher: hygiene reports disabled (set hygiene.enabled=true); no hygiene report was produced');
        }
        if (($text = $result->text()) !== '') {
            $human->writeln($text);
        }
        if ($json) {
            $this->line($result->toJson(), null, \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL);
        }

        return $result->exitCode();
    }

    private function string(string $option): ?string
    {
        $v = $this->option($option);

        return is_string($v) && $v !== '' ? $v : null;
    }

}
