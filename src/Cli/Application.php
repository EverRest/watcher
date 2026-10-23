<?php

declare(strict_types=1);

namespace Watcher\Cli;

use Closure;
use DateTimeImmutable;
use Watcher\Config\InvalidConfig;
use Watcher\RunResult;

/** Plain-PHP entry point behind bin/watcher. Streams and factory are injected so it is testable. */
final class Application
{
    private const USAGE = "usage: watcher run --config=<file returning the config array> [--now=<ISO-8601>]\n";

    /**
     * @param Closure(array<string, mixed>): (callable(?DateTimeImmutable): RunResult) $factory throws InvalidConfig before any external call
     * @param resource $err
     */
    public function __construct(private readonly Closure $factory, private $err)
    {
    }

    /** Strict ISO-8601 (date, time, offset or Z); null when it is anything else. */
    public static function parseNow(string $value): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value);
        $e = DateTimeImmutable::getLastErrors();

        return $d === false || ($e !== false && ($e['warning_count'] > 0 || $e['error_count'] > 0)) ? null : $d;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $args = array_slice($argv, 1);
        $config = null;
        $nowRaw = null;
        foreach (array_slice($args, 1) as $a) {
            if (str_starts_with($a, '--config=')) {
                $config = substr($a, 9);
            } elseif (str_starts_with($a, '--now=')) {
                $nowRaw = substr($a, 6);
            } else {
                return $this->usage();
            }
        }
        if (($args[0] ?? null) !== 'run' || $config === null || $config === '' || !is_file($config)) {
            return $this->usage();
        }

        $now = null;
        if ($nowRaw !== null && ($now = self::parseNow($nowRaw)) === null) {
            fwrite($this->err, "invalid --now: expected ISO-8601, e.g. 2026-10-23T06:00:00Z\n");

            return 2;
        }

        try {
            $values = (static fn (string $__path): mixed => require $__path)($config);
            if (!is_array($values)) {
                throw new InvalidConfig('config file must return an array');
            }
            $result = ($this->factory)($values)($now);
        } catch (InvalidConfig $e) {
            fwrite($this->err, 'invalid config: ' . $e->getMessage() . "\n");

            return 2;
        }

        return $result->exitCode();
    }

    private function usage(): int
    {
        fwrite($this->err, self::USAGE);

        return 2;
    }
}
