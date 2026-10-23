<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Closure;
use Illuminate\Console\Command;
use Watcher\Config\InvalidConfig;
use Watcher\Operate\HeartbeatStatus;

final class StatusCommand extends Command
{
    protected $signature = 'watcher:status {--json : One JSON line on stdout, the text on stderr}';

    protected $description = 'Show the last watcher run and whether it is overdue (exit 0 only when ok)';

    /** @param Closure(): HeartbeatStatus $factory throws InvalidConfig for a bad config */
    public function __construct(private readonly Closure $factory, private readonly string $service = '')
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $status = ($this->factory)();
        } catch (InvalidConfig $e) {
            $this->error('invalid config: ' . $e->getMessage());

            return 2;
        }

        $service = is_string($status->record['service'] ?? null) ? $status->record['service'] : $this->service;
        $text = rtrim($status->text($service), "\n");
        if ($this->option('json')) {
            $this->output->getErrorStyle()->writeln($text);
            $this->line(json_encode($status->toArray($service), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        } else {
            $this->line($text);
        }

        return $status->exitCode();
    }
}
