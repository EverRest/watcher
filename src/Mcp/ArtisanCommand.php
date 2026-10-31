<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/**
 * Runs one `artisan` command in a child process with an argument list (no shell). The child's own output is not forwarded:
 * stdout is the MCP protocol channel, and a digest or log line may hold text. Only the exit code comes back, plus the JSON
 * last line when the command was given `--json`.
 */
final class ArtisanCommand
{
    public function __construct(private readonly string $php, private readonly string $artisan, private readonly int $timeoutSeconds = 1800)
    {
    }

    /**
     * @param list<string> $flags
     * @return array{exit: int|string, json?: array<string, mixed>}
     */
    public function run(string $command, array $flags): array
    {
        $argv = [$this->php, $this->artisan, $command, ...$flags];
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['exit' => 'error'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // drained, not returned
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);

                return ['exit' => 'timeout'];
            }
            usleep(100_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $out = ['exit' => $code];
        if (in_array('--json', $flags, true)) {
            $last = trim((string) strrchr(rtrim($stdout), "\n"));
            $json = json_decode($last === '' ? rtrim($stdout) : $last, true);
            if (is_array($json)) {
                $out['json'] = $json;
            }
        }

        return $out;
    }
}
