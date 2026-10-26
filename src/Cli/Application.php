<?php

declare(strict_types=1);

namespace Watcher\Cli;

use Closure;
use DateTimeImmutable;
use Watcher\Config\InvalidConfig;
use Watcher\Fleet\FleetCheck;
use Watcher\Fleet\FleetResult;
use Watcher\Fleet\FleetStatus;
use Watcher\Hygiene\HygieneOptions;
use Watcher\Hygiene\HygieneResult;
use Watcher\Operate\CheckReport;
use Watcher\Operate\Doctor;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunOptions;
use Watcher\Operate\RunSummaryData;
use Watcher\Report\EventLogException;
use Watcher\Report\ReportRunResult;
use Watcher\Report\Week;
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
          watcher state:get <heartbeat|last-snapshot|snapshot:<window end>|run:<window end>> --config=<file>
          watcher hygiene --config=<file> [--report=privacy,shape,proposals,cost] [--days=<1-30>] [--now=<ISO-8601>] [--notify] [--decline=<proposal id>] [--json]
          watcher fleet:run --config=<fleet file or directory> [--now=<ISO-8601>] [--only=<service>[,<service>...]] [--dry-run [--estimate]] [--json]
          watcher fleet:check --config=<fleet file or directory> [--post-test] [--json]
          watcher fleet:status --config=<fleet file or directory> [--json]
          watcher report --weekly --config=<file> [--week=YYYY-Www] [--dry-run] [--json]

        TXT;

    public const DISABLED_HINT = 'watcher: disabled (set enabled=true / WATCHER_ENABLED=1)';

    /** Flags each command accepts (besides --config=). */
    private const FLAGS = [
        'run' => ['--dry-run', '--estimate', '--json'],
        'check' => ['--post-test', '--json'],
        'status' => ['--json'],
        'state:get' => [],
        'hygiene' => ['--notify', '--json', '--dry-run'],
        'fleet:run' => ['--dry-run', '--estimate', '--json'],
        'fleet:check' => ['--post-test', '--json'],
        'fleet:status' => ['--json'],
        'report' => ['--weekly', '--dry-run', '--json'],
    ];

    /**
     * @param Closure(array<string, mixed>, RunOptions): (callable(?DateTimeImmutable): RunResult) $factory throws InvalidConfig before any external call
     * @param resource $err
     * @param resource|null $out receives the primary output (the summary line, the check report, the status)
     * @param (Closure(array<string, mixed>, bool): CheckReport)|null $checkFactory builds the Doctor and runs it; InvalidConfig becomes a failed `config` check
     * @param (Closure(array<string, mixed>): HeartbeatStatus)|null $statusFactory
     * @param (Closure(string, RunOptions, list<string>): (callable(?DateTimeImmutable): FleetResult))|null $fleetFactory
     *        gets the fleet file or directory, the options and the --only names; throws InvalidConfig before any external call
     * @param (Closure(array<string, mixed>, string): ?string)|null $stateGetFactory config and logical key to the redacted text, null when nothing is stored;
     *        throws InvalidArgumentException for a refused key, InvalidConfig for a bad config
     * @param (Closure(string, bool): CheckReport)|null $fleetCheckFactory fleet file or directory, --post-test; throws InvalidConfig for an invalid fleet
     * @param (Closure(string): FleetStatus)|null $fleetStatusFactory fleet file or directory; throws InvalidConfig for an invalid fleet
     * @param (Closure(array<string, mixed>, HygieneOptions): HygieneResult)|null $hygieneFactory spec 006; throws InvalidConfig before any external call
     * @param (Closure(array<string, mixed>, ?Week, bool): ReportRunResult)|null $reportFactory spec 005 weekly report
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
        private readonly ?Closure $stateGetFactory = null,
        private readonly ?Closure $hygieneFactory = null,
        private readonly ?Closure $reportFactory = null,
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
        $key = null;
        $hygieneArgs = ['report' => null, 'days' => null, 'decline' => null];
        $weekRaw = null;
        $flags = [];
        foreach (array_slice($args, 1) as $a) {
            if (str_starts_with($a, '--config=')) {
                $config = substr($a, 9);
            } elseif ($command === 'state:get' && $key === null && !str_starts_with($a, '-')) {
                $key = $a;
            } elseif (in_array($command, ['run', 'fleet:run', 'hygiene'], true) && str_starts_with($a, '--now=')) {
                $nowRaw = substr($a, 6);
            } elseif ($command === 'hygiene' && preg_match('/^--(report|days|decline)=(.*)$/s', $a, $m) === 1) {
                $hygieneArgs[$m[1]] = $m[2];
            } elseif ($command === 'report' && str_starts_with($a, '--week=')) {
                $weekRaw = substr($a, 7);
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

        if ($command === 'state:get') {
            return $key === null ? $this->usage() : $this->stateGet($config, $key);
        }

        if ($command === 'hygiene') {
            return $this->hygiene($config, $hygieneArgs, $nowRaw, isset($flags['--notify']), $json, isset($flags['--dry-run']));
        }

        if ($command === 'report') {
            return $this->weeklyReport($config, isset($flags['--weekly']), $weekRaw, isset($flags['--dry-run']), $json);
        }

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
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }

        if ($result->outcome === RunOutcome::Disabled) {
            fwrite($this->err, self::DISABLED_HINT . "\n");
        }
        $this->line($json, 'watcher: ' . $result->summary(), true);
        if ($json) {
            $this->line(null, (new JsonSummary())->render($result->summaryData ?? new RunSummaryData($result->outcome, null, $result->failureReason)));
        }

        return $result->exitCode();
    }

    private function stateGet(string $config, string $key): int
    {
        if ($this->stateGetFactory === null) {
            return $this->usage();
        }
        try {
            $text = ($this->stateGetFactory)($this->load($config), $key);
        } catch (InvalidConfig $e) {
            fwrite($this->err, 'invalid config: ' . $e->getMessage() . "\n");

            return 2;
        } catch (\InvalidArgumentException $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return 2;
        } catch (\Throwable $e) {
            fwrite($this->err, 'state unavailable (' . $e::class . ")\n"); // class only: a driver message can carry host and credentials

            return 1;
        }
        if ($text === null) {
            fwrite($this->err, "state:get: no entry stored for that key\n");

            return 1;
        }
        $this->line(null, $text);

        return 0;
    }

    private function weeklyReport(string $config, bool $weekly, ?string $weekRaw, bool $dryRun, bool $json): int
    {
        if (!$weekly) {
            fwrite($this->err, "report needs --weekly\n");

            return 2;
        }
        if ($this->reportFactory === null) {
            return $this->usage();
        }
        $week = null;
        if ($weekRaw !== null && $weekRaw !== '') {
            try {
                $week = Week::fromIso($weekRaw);
            } catch (\InvalidArgumentException) {
                fwrite($this->err, "invalid --week: expected YYYY-Www\n");

                return 2;
            }
        }
        try {
            $values = $this->load($config);
            $result = ($this->reportFactory)($values, $week, $dryRun);
        } catch (InvalidConfig $e) {
            fwrite($this->err, 'invalid config: ' . $e->getMessage() . "\n");

            return 2;
        } catch (EventLogException $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return 1;
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }
        if ($result->outcome === ReportRunResult::OUTCOME_DISABLED) {
            fwrite($this->err, self::DISABLED_HINT . "\n");
        }
        if ($result->text !== null) {
            $this->line($json, $result->text, true);
        } elseif ($result->outcome === ReportRunResult::OUTCOME_ALREADY) {
            $this->line($json, 'report already sent', true);
        }
        if ($json && $result->data !== null) {
            $this->line(null, json_encode($result->data->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        }

        return $result->exitCode();
    }

    /** @param array{report: ?string, days: ?string, decline: ?string} $args */
    private function hygiene(string $config, array $args, ?string $nowRaw, bool $notify, bool $json, bool $dryRun = false): int
    {
        if ($this->hygieneFactory === null) {
            return $this->usage();
        }
        $now = null;
        if ($nowRaw !== null && ($now = self::parseNow($nowRaw)) === null) {
            fwrite($this->err, "invalid --now: expected ISO-8601, e.g. 2026-10-23T06:00:00Z\n");

            return 2;
        }
        try {
            $options = HygieneOptions::fromInput($args['report'], $args['days'], $args['decline'], $now, $notify, $dryRun);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->err, $e->getMessage() . "\n");

            return 2;
        }

        try {
            $result = ($this->hygieneFactory)($this->load($config), $options);
        } catch (InvalidConfig $e) {
            fwrite($this->err, 'invalid config: ' . $e->getMessage() . "\n");

            return 2;
        }
        if ($result->disabled) {
            fwrite($this->err, self::DISABLED_HINT . "\n");
        }
        if ($result->skipped) {
            fwrite($this->err, "watcher: hygiene reports disabled (set hygiene.enabled=true); only the cost report was produced\n");
        }
        if (($text = $result->text()) !== '') {
            $this->line($json, $text, true);
        }
        if ($json) {
            $this->line(null, $result->toJson());
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
        } catch (\Throwable $e) {
            return $this->unexpected($e);
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
        } catch (\Throwable $e) {
            return $this->unexpected($e);
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
        } catch (\Throwable $e) {
            return $this->unexpected($e);
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

    private function unexpected(\Throwable $e): int
    {
        fwrite($this->err, $e::class . ': ' . $e->getMessage() . "\n");

        return 1;
    }
}
