<?php

declare(strict_types=1);

namespace Watcher\Laravel;

use Illuminate\Console\Command;
use Watcher\Mcp\McpCommand as Server;

/**
 * `php artisan watcher:mcp` (spec 015): the same stdio server. stdout is the protocol channel, so nothing else may print.
 * INTEGRATOR: register it in {@see WatcherServiceProvider} beside CheckCommand (`McpCommand::class` in `$this->commands([...])`, no constructor arguments).
 */
final class McpCommand extends Command
{
    protected $signature = 'watcher:mcp {--config= : Configuration file returning the array} {--setup : Setup copilot with no configuration}';

    protected $description = 'Serve the read-only MCP server over stdio (status, check, setup copilot, docs)';

    public function handle(): int
    {
        $argv = ['artisan', 'mcp'];
        $config = $this->option('config');
        if (is_string($config) && $config !== '') {
            $argv[] = '--config=' . $config;
        }
        if ($this->option('setup') === true) {
            $argv[] = '--setup';
        }

        return Server::main($argv, STDIN, STDOUT, STDERR);
    }
}
