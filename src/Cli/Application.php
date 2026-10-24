<?php

declare(strict_types=1);

namespace Watcher\Cli;

use Closure;
use DateTimeImmutable;
use Watcher\Config\InvalidConfig;
use Watcher\Fleet\FleetCheck;
use Watcher\Fleet\FleetResult;
use Watcher\Fleet\FleetStatus;
use Watcher\Operate\CheckReport;
use Watcher\Operate\Doctor;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunOptions;
use Watcher\Operate\RunSummaryData;
use Watcher\RunOutcome;
use Watcher\RunResult;

/** Plain-PHP entry point behind bin/watcher. Streams and factories are injected so it is testable. */
final class Application
{
    private const USAGE = <<<'TXT'
        usage:
          watcher run --config=<file returning the config array> [--now=<ISO-8601>] [--dry-run [--estimate]] [--json]
          watcher check --config=<file> [--post-test] [--json]
          watcher status --config=<file> [--json]
          watcher fleet:run --config=<fleet file or directory> [--now=<ISO-8601>] [--only=<service>[,<service>...]] [--dry-run [--estimate]] [--json]
          watcher fleet:check --config=<fleet file or directory> [--post-test] [--json]
          watcher fleet:status --config=<fleet file or directory> [--json]

        TXT;

    /** Flags each command accepts (besides --config=). */
    private const FLAGS = [
        'run' => ['--dry-run', '--estimate', '--json'],
        'check' => ['--post-test', '--json'],
        'status' => ['--json'],
        'fleet:run' => ['--dry-run', '--estimate', '--json'],
        'fleet:check' => ['--post-test', '--json'],
        'fleet:status' => ['--json'],
    ];

    /**
     * @param Closure(array<string, mixed>, RunOptions): (callable(?DateTimeImmutable): RunResult) $factory throws InvalidConfig before any external call
     * @param resource $err
     * @param resource|null $out receives the primary output (the summary line, the check report, the status)
     * @param (Closure(array<string, mixed>, bool): CheckReport)|null $checkFactory builds the Doctor and runs it; InvalidConfig becomes a failed `config` check
     * @param (Closure(array<string, mixed>): HeartbeatStatus)|null $statusFactory
     * @param (Closure(string, RunOptions, list<string>): (callable(?DateTimeImmutable): FleetResult))|null $fleetFactory
     *        gets the fleet file or directory, the options and the --only names; throws InvalidConfig before any external call
     * @param (Closure(string, bool): CheckReport)|null $fleetCheckFactory fleet file or directory, --post-test; throws InvalidConfig for an invalid fleet
     * @param (Closure(string): FleetStatus)|null $fleetStatusFactory fleet file or directory; throws InvalidConfig for an invalid fleet
     */
    public function __construct(
        private readonly Closure $factory,
        private $err,
        private $out = null,
        private readonly ?Closure $checkFactory = null,
        private readonly ?Closure $statusFactory = null,
        private readonly ?Closure $fleetFactory = null,
        private readonly ?Closure $fleetCheckFactory = null,
        private readonly ?Closure $fleetStatusFactory = null,
    ) {
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
        $command = $args[0] ?? '';
        if (!isset(self::FLAGS[$command])) {
            return $this->usage();
        }
        $config = null;
        $nowRaw = null;
        $only = null;
        $flags = [];
        foreach (array_slice($args, 1) as $a) {
            if (str_starts_with($a, '--config=')) {
                $config = substr($a, 9);
            } elseif (($command === 'run' || $command === 'fleet:run') && str_starts_with($a, '--now=')) {
                $nowRaw = substr($a, 6);
            } elseif ($command === 'fleet:run' && str_starts_with($a, '--only=')) {
                $only = self::parseOnly(substr($a, 7));
                if ($only === []) {
                    return $this->usage();
                }
            } elseif (in_array($a, self::FLAGS[$command], true)) {
                $flags[$a] = true;
            } else {
                return $this->usage();
            }
        }
        if ($config === null || $config === '' || !(is_file($config) || (str_starts_with($command, 'fleet:') && is_dir($config)))) {
            return $this->usage();
        }
        $json = isset($flags['--json']);

        if ($command === 'fleet:check' || $command === 'fleet:status') {
            return $this->fleetOperate($command, $config, $json, isset($flags['--post-test']));
        }
        if ($command === 'check' || $command === 'status') {
            return $this->operate($command, $config, $json, isset($flags['--post-test']));
        }

        if (isset($flags['--estimate']) && !isset($flags['--dry-run'])) {
            fwrite($this->err, "--estimate requires --dry-run\n");

            return 2;
        }
        $now = null;
        if ($nowRaw !== null && ($now = self::parseNow($nowRaw)) === null) {
            fwrite($this->err, "invalid --now: expected ISO-8601, e.g. 2026-10-23T06:00:00Z\n");

            return 2;
        }

        if ($command === 'fleet:run') {
            return $this->fleet($config, $now, new RunOptions(isset($flags['--dry-run']), isset($flags['--estimate']), $json), $only ?? []);
        }

        $values = null;
        try {
            $values = $this->load($config);
            $result = ($this->factory)($values, new RunOptions(isset($flags['--dry-run']), isset($flags['--estimate']), $json))($now);
        } catch (InvalidConfig $e) {
            fwrite($this->err, 'invalid config: ' . $e->getMessage() . "\n");
            if ($json) {
                $service = is_array($values) && is_string($values['service'] ?? null) ? $values['service'] : null;
                $this->line(null, (new JsonSummary())->render(new RunSummaryData(RunOutcome::InvalidConfig, $service, 'invalid_config')));
            }

            return 2;
        }

        $this->line($json, 'watcher: ' . $result->summary(), true);
        if ($json) {
            $this->line(null, (new JsonSummary())->render($result->summaryData ?? new RunSummaryData($result->outcome, null, $result->failureReason)));
        }

        return $result->exitCode();
    }

    /** @param list<string> $only */
    private function fleet(string $config, ?DateTimeImmutable $now, RunOptions $options, array $only): int
    {
        if ($this->fleetFactory === null) {
            return $this->usage();
        }
        try {
            $result = ($this->fleetFactory)($config, $options, $only)($now);
        } catch (InvalidConfig $e) {
            fwrite($this->err, 'invalid fleet: ' . $e->getMessage() . "\n");
            if ($options->json) {
                $this->line(null, FleetResult::invalidJson());
            }

            return 2;
        }
        foreach ($result->lines() as $line) {
            $this->line($options->json, $line, true);
        }
        if ($options->json) {
            $this->line(null, $result->toJson());
        }

        return $result->exitCode();
    }

    private function fleetOperate(string $command, string $config, bool $json, bool $postTest): int
    {
        $factory = $command === 'fleet:check' ? $this->fleetCheckFactory : $this->fleetStatusFactory;
        if ($factory === null) {
            return $this->usage();
        }
        try {
            if ($command === 'fleet:status') {
                $status = $factory($config);
                $this->line($json, rtrim($status->text(), "\n"), true);
                if ($json) {
                    $this->line(null, $status->toJson());
                }

                return $status->exitCode();
            }
            $report = $factory($config, $postTest);
        } catch (InvalidConfig $e) {
            if ($command === 'fleet:status') {
                fwrite($this->err, 'invalid fleet: ' . $e->getMessage() . "\n");

                return 2;
            }
            $report = FleetCheck::invalid($e->getMessage());
        }
        $this->line($json, rtrim($report->text(), "\n"), true);
        if ($json) {
            $this->line(null, json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return $report->exitCode();
    }

    /** @return list<string> the service names of an --only=a,b value (empty when none) */
    public static function parseOnly(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $n): bool => $n !== ''));
    }

    private function operate(string $command, string $config, bool $json, bool $postTest): int
    {
        $factory = $command === 'check' ? $this->checkFactory : $this->statusFactory;
        if ($factory === null) {
            return $this->usage();
        }
        try {
            $values = $this->load($config);
            if ($command === 'check') {
                $report = $factory($values, $postTest);
            } else {
                return $this->status($values, $factory($values), $json);
            }
        } catch (InvalidConfig $e) {
            if ($command === 'status') {
                fwrite($this->err, 'invalid config: ' . $e->getMessage() . "\n");

                return 2;
            }
            $report = Doctor::configFailure('invalid: ' . $e->getMessage());
        }
        $this->line($json, rtrim($report->text(), "\n"), true);
        if ($json) {
            $this->line(null, json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return $report->exitCode();
    }

    /** @param array<string, mixed> $values */
    private function status(array $values, HeartbeatStatus $s, bool $json): int
    {
        $rec = $s->record;
        $service = is_string($rec['service'] ?? null) ? $rec['service'] : (is_string($values['service'] ?? null) ? $values['service'] : '');
        $this->line($json, rtrim($s->text($service), "\n"), true);
        if ($json) {
            $this->line(null, json_encode($s->toArray($service), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        }

        return $s->exitCode();
    }

    /**
     * Human lines go to stdout, or to stderr when $json (stdout is then the single JSON line).
     * Pass $json = null for the machine line itself, always stdout.
     */
    private function line(?bool $json, string $text, bool $human = false): void
    {
        $stream = $human && $json === true ? $this->err : $this->out;
        if ($stream !== null) {
            fwrite($stream, $text . "\n");
        }
    }

    /** @return array<string, mixed> */
    private function load(string $config): array
    {
        $values = (static fn (string $__path): mixed => require $__path)($config);
        if (!is_array($values)) {
            throw new InvalidConfig('config file must return an array');
        }

        return $values;
    }

    private function usage(): int
    {
        fwrite($this->err, self::USAGE);

        return 2;
    }
}
