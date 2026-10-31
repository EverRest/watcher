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
    private const TAIL_BYTES = 262_144;

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

        // Only the tail is kept: the JSON line is the last one, and a long digest must not grow memory without bound.
        $stdout = '';
        $deadline = microtime(true) + $this->timeoutSeconds;
        $exit = null;
        while ($exit === null) {
            $stdout = substr($stdout . (string) stream_get_contents($pipes[1]), -self::TAIL_BYTES);
            stream_get_contents($pipes[2]); // drained, not returned
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = $status['exitcode'];
            } elseif (microtime(true) > $deadline) {
                proc_terminate($proc);
                $exit = 'timeout';
            } else {
                usleep(100_000);
            }
        }
        $stdout = substr($stdout . (string) stream_get_contents($pipes[1]), -self::TAIL_BYTES);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc); // the exit code is already taken from proc_get_status: proc_close may report -1 after it

        $out = ['exit' => $exit];
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
