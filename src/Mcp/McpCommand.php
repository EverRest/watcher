<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Psr\Clock\ClockInterface;
use Throwable;
use Watcher\Analyze\VerdictCache;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Mcp\Tools\CheckTool;
use Watcher\Mcp\Tools\DryRunTool;
use Watcher\Mcp\Tools\ExplainConfigTool;
use Watcher\Mcp\Tools\WatcherActions;
use Watcher\Mcp\Tools\ExplainOutcomeTool;
use Watcher\Mcp\Tools\FindingTool;
use Watcher\Mcp\Tools\FirstDayTool;
use Watcher\Mcp\Tools\GenerateConfigTool;
use Watcher\Mcp\Tools\HygieneTool;
use Watcher\Mcp\Tools\LatestReportTool;
use Watcher\Mcp\Tools\RolloutCheckTool;
use Watcher\Mcp\Tools\SearchDocsTool;
use Watcher\Mcp\Tools\SetupPlanTool;
use Watcher\Mcp\Tools\StatusTool;
use Watcher\Mcp\Tools\ValidateConfigTool;
use Watcher\Operate\CheckReport;
use Watcher\Operate\CheckResult;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\RefusedAccess;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Schedule\SystemClock;
use Watcher\State\StateStore;

/**
 * `watcher mcp --config=<file>` / `watcher mcp --setup` (contracts/cli.md). Validates everything before the first read of stdin: a failure
 * is one stderr line naming the setting and exit 2 with an empty stdout. Exit 0 at stdin EOF, 1 on an internal fatal error (class name only).
 */
final class McpCommand
{
    private const USAGE = "usage:\n  watcher mcp --config=<file>\n  watcher mcp --setup\n";

    /**
     * @param list<string> $argv as in `$argv` (the first is the script, the second `mcp`)
     * @param resource $in
     * @param resource $out
     * @param resource $err
     * @param (callable(WatchConfig, StateStore): CheckReport)|null $check INTEGRATOR: runs a `Doctor` built with `readOnly: true` over the given read-only store (T022)
     * @param (callable(WatchConfig, StateStore): DryRunPipeline)|null $dryRun INTEGRATOR: the adapter over `Watcher` with `RunOptions::dryRun()` and a discarding output (T021)
     */
    public static function main(array $argv, $in, $out, $err, ?ClockInterface $clock = null, ?callable $check = null, ?callable $dryRun = null): int
    {
        $clock ??= new SystemClock();
        $args = array_slice($argv, 2);
        $config = null;
        $setup = false;
        foreach ($args as $a) {
            if (str_starts_with($a, '--config=') && $config === null) {
                $config = substr($a, 9);
            } elseif ($a === '--setup' && !$setup) {
                $setup = true;
            } else {
                return self::usage($err);
            }
        }
        if ($setup === ($config !== null) || ($config !== null && ($config === '' || !is_file($config)))) {
            return self::usage($err);
        }

        try {
            $server = $setup ? self::setupServer($in, $out, $err, $clock) : self::configServer((string) $config, $in, $out, $err, $clock, $check, $dryRun);
        } catch (InvalidConfig $e) {
            fwrite($err, 'watcher [error] invalid config: ' . $e->getMessage() . "\n");

            return 2;
        } catch (UsageError $e) {
            fwrite($err, 'watcher [error] ' . $e->getMessage() . "\n");

            return 2;
        } catch (Throwable $e) {
            fwrite($err, 'watcher [error] ' . $e::class . "\n");

            return 1;
        }
        try {
            return $server->serve();
        } catch (Throwable $e) {
            fwrite($err, 'watcher [error] ' . $e::class . "\n");

            return 1;
        }
    }

    /** @param resource $err */
    private static function usage($err): int
    {
        fwrite($err, self::USAGE);

        return 2;
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    private static function setupServer($in, $out, $err, ClockInterface $clock): Server
    {
        $root = dirname(__DIR__, 2);
        // F1: no configured file yet, so no PHP file is approved; a root at the cwd would run any PHP file of the project
        $tools = [...self::onboardingTools(new PathPolicy([]), null, $clock), ...self::docsTools($root)];

        return self::assemble($tools, ['onboarding', 'docs'], false, null, $in, $out, $err, $clock, $root);
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     * @param (callable(WatchConfig, StateStore): CheckReport)|null $check
     * @param (callable(WatchConfig, StateStore): DryRunPipeline)|null $dryRun
     */
    private static function configServer(string $file, $in, $out, $err, ClockInterface $clock, ?callable $check, ?callable $dryRun): Server
    {
        $values = (static fn (string $__p): mixed => require $__p)($file);
        if (!is_array($values)) {
            throw new InvalidConfig('config file must return an array');
        }
        if (array_key_exists('fleet', $values)) {
            throw new UsageError('a fleet file is not supported by watcher mcp (key: fleet); point --config at one service');
        }
        $mcp = $values['mcp'] ?? [];
        unset($values['mcp']);
        $settings = McpSettings::fromArray(is_array($mcp) ? $mcp : throw new InvalidConfig('mcp must be an array'));
        $c = WatchConfig::fromArray($values);

        $log = new StderrSink($err);
        // Master switch off: do not open the configured store (and do not build a dry-run pipeline).
        $store = $c->enabled ? self::store($c, $clock, $log) : new ReadOnlyStateStore(RefusedAccess::state());
        $normalizer = new Normalizer($c->on('dependencies') ? array_map('strval', array_keys($c->dependencies)) : [], $c->dependencyDomains, extraPatterns: $c->redactionPatterns);
        $pathMap = is_array($c->repo['path_map'] ?? null) ? array_map('strval', $c->repo['path_map']) : [];
        $guard = new OutputGuard(new Redactor($normalizer, $c->redactionPatterns, $pathMap));
        $heartbeat = $c->enabled && $c->on('heartbeat');
        $inspect = [
            new StatusTool(
                static fn (): HeartbeatStatus => Factory::heartbeat($c, $store, $clock)->status($c->heartbeat['stale_after']),
                static fn (): \DateTimeImmutable => $clock->now(),
                $guard,
                $heartbeat,
            ),
            new CheckTool(static fn (): CheckReport => $check !== null ? $check($c, $store) : self::configOnlyReport()),
            new LatestReportTool(new LastReport($store, $c->stateKey), $guard, $c->enabled),
            new FindingTool($store, new VerdictCache($store, $c->stateKey, $c->state['retention']), $c->stateKey, array_map('strval', array_keys($c->dependencies)), $guard, $c->enabled),
            new HygieneTool(new NoHygieneSource(), $c->enabled),
        ];
        $allowDry = false;
        if ($c->enabled && $settings->allowDryRun && $dryRun !== null) {
            $inspect[] = new DryRunTool($dryRun($c, $store), new DryRunGuard(), $guard);
            $allowDry = true;
        } elseif ($c->enabled && $settings->allowDryRun) {
            fwrite($err, "watcher [error] mcp.allow_dry_run is set but this build has no dry-run adapter; watcher_dry_run is not offered\n");
        }
        $root = dirname(__DIR__, 2);
        // F1: the configured file itself is the only PHP file a client may name; its directory's other files are not approved
        // Actions run the artisan commands of this deployment, with the artisan path the settings name (never the working directory).
        if (in_array('actions', $settings->groups, true) && $c->enabled && $settings->artisan === null) {
            throw new InvalidConfig('mcp.artisan (or WATCHER_MCP_ARTISAN) is required when mcp.groups includes actions');
        }
        $actions = in_array('actions', $settings->groups, true) && $c->enabled
            ? WatcherActions::tools(new ArtisanCommand(PHP_BINARY, (string) $settings->artisan), static function (string $tool, array $data) use ($err): void {
                fwrite($err, 'watcher [audit] ' . $tool . ' exit=' . (string) ($data['exit'] ?? '?') . "\n");
            }, static fn (): int => $clock->now()->getTimestamp())
            : [];
        $tools = [...$inspect, ...self::onboardingTools(PathPolicy::forFile($file), static fn (WatchConfig $w): StateStore => self::store($w, $clock, $log), $clock), ...self::docsTools($root), ...$actions];

        return self::assemble($tools, $settings->groups, $allowDry, $c->service, $in, $out, $err, $clock, $root);
    }

    /** What `watcher_check` can say until a read-only `Doctor` is wired: the configuration loaded, nothing else was run. */
    private static function configOnlyReport(): CheckReport
    {
        $rest = ['source', 'provider_auth', 'models', 'state', 'notifier'];

        return new CheckReport([CheckResult::pass('config'), ...array_map(static fn (string $n): CheckResult => CheckResult::skip($n, 'unavailable'), $rest)]);
    }

    private static function store(WatchConfig $c, ClockInterface $clock, StderrSink $log): StateStore
    {
        if (($c->state['driver'] ?? null) === 'file') {
            return new ReadOnlyFileStore((string) $c->state['file']['path'], $clock);
        }

        return new ReadOnlyStateStore(Factory::state($c, $clock, $log));
    }

    /**
     * @param (\Closure(WatchConfig): StateStore)|null $stateFactory
     * @return list<Tool>
     */
    private static function onboardingTools(PathPolicy $policy, ?\Closure $stateFactory, ClockInterface $clock): array
    {
        $planner = new SetupPlanner();
        $reader = new ConfigFileReader();
        $validator = new ConfigFileValidator();
        $version = self::version();
        $inspector = new RolloutInspector(
            $policy,
            $reader,
            $validator,
            static fn (string $e): bool => extension_loaded($e),
            static fn (string $c): bool => class_exists($c),
            $stateFactory ?? static fn (WatchConfig $w): StateStore => new ReadOnlyStateStore(Factory::state($w, $clock)),
            $version,
            PHP_VERSION_ID,
            (string) getcwd(),
        );

        return [new SetupPlanTool($planner), new ValidateConfigTool($policy, $reader, $validator), new GenerateConfigTool(new ConfigGenerator()), new FirstDayTool($planner), new RolloutCheckTool($inspector)];
    }

    /** @return list<Tool> */
    private static function docsTools(string $root): array
    {
        return [new SearchDocsTool(new DocsIndex($root)), new ExplainConfigTool(), new ExplainOutcomeTool()];
    }

    /**
     * @param list<Tool> $tools
     * @param list<string> $groups
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    private static function assemble(array $tools, array $groups, bool $allowDry, ?string $service, $in, $out, $err, ClockInterface $clock, string $root): Server
    {
        $secret = new SecretGuard();
        $registry = new ToolRegistry($tools, $groups, $allowDry, new Envelope($service, $clock), $secret, $err);
        $docs = in_array('docs', $groups, true) ? new PromptCatalog(new DocsIndex($root), $registry->names(), $secret) : null;

        return new Server($registry, self::version(), $in, $out, $err, $docs);
    }

    private static function version(): string
    {
        $v = @file_get_contents(dirname(__DIR__, 2) . '/VERSION');

        return $v === false ? 'unknown' : trim($v);
    }
}
