<?php

declare(strict_types=1);

namespace Watcher\Config;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Redis;
use Watcher\Analyze\Analyzer;
use Watcher\Config\Build\AnalyzerFactory;
use Watcher\Config\Build\ObserveFactory;
use Watcher\Config\Build\SourceFactory;
use Watcher\Config\Build\StateFactory;
use Watcher\Issues\IssueSource;
use Watcher\Metrics\MetricSource;
use Watcher\ApiContext\ApiContextReader;
use Watcher\ApiContext\ApiSpecCache;
use Watcher\ApiContext\OpenApiReducer;
use Watcher\ApiContext\OpenApiSource;
use Watcher\Notify\DiscordNotifier;
use Watcher\Observe\ObserveCounters;
use Watcher\Observe\SinkDispatcher;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Notify\Notifier;
use Watcher\Notify\RoutingNotifier;
use Watcher\Operate\Doctor;
use Watcher\Operate\Heartbeat;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\RefusedAccess;
use Watcher\Schedule\SystemClock;
use Watcher\Notify\SlackWebhookNotifier;
use Watcher\Notify\TelegramNotifier;
use Watcher\Source\LogSource;
use Watcher\State\PdoStateStore;
use Watcher\Operate\StateInspector;
use Watcher\Schedule\RunGate;
use Watcher\State\Redis\RedisClient;
use Watcher\State\RenewableLock;
use Watcher\State\StateStore;

/** Builds the real collaborators from a validated config. The pdo table is not created here (see PdoStateStore::createTableSql). */
final class Factory
{
    public static function http(): Client
    {
        return new Client(self::httpOptions());
    }

    /** @return array{timeout: int, connect_timeout: int} sources and Slack: short calls */
    public static function httpOptions(): array
    {
        return ['timeout' => 30, 'connect_timeout' => 10];
    }

    /**
     * A long analysis reply can take well over 30 s, but a model call (two sequential attempts) must stay within
     * a quarter of lock_ttl, so a second run cannot start while this one is still analysing: timeout = lock_ttl / 4 / 2, within 10..180 s.
     * When the store supports {@see RenewableLock}, Watcher renews the run lock between pipeline steps and before each model call; the router makes at most 2 + escalation_cap model calls.
     *
     * @return array{timeout: int, connect_timeout: int}
     */
    public static function analyzerHttpOptions(int $lockTtl = 900): array
    {
        return ['timeout' => max(10, min(180, intdiv($lockTtl, 8))), 'connect_timeout' => 10];
    }



    /** Wall-clock seconds one metrics or Sentry call (retries and pages included) may take; both sources share this one figure. */
    public const SOURCE_BUDGET_SECONDS = 60;














    /** $lockTtl bounds the rate-limit wait of the chat notifiers: min(60, max(1, lockTtl / 10)) seconds per message. */
    public static function notifier(Client $http, int $lockTtl = 900): Notifier
    {
        $timeout = min(60, max(1, intdiv($lockTtl, 10)));

        return new RoutingNotifier(new SlackWebhookNotifier($http, $timeout), new TelegramNotifier($http, $timeout), new DiscordNotifier($http, $timeout));
    }




    /** No provider has a model list now, so `check` reports provider_auth and models as skipped (Doctor takes null). */
    public static function modelCatalog(WatchConfig $c, Client $http): null
    {
        return null;
    }

    /** The OpenAPI context reader (spec 010); null when the section is absent or switched off, so nothing is built, read or cached. */
    public static function apiContextReader(WatchConfig $config, Client $http, StateStore $state, Redactor $redactor, ?ClockInterface $clock = null): ?ApiContextReader
    {
        $a = $config->apiContext;
        if ($a === null || !$a['enabled']) {
            return null;
        }

        return new ApiContextReader(
            source: new OpenApiSource($http, WatchConfig::reveal($a['source']), $a['auth_token'] === null ? null : WatchConfig::reveal($a['auth_token']), $a['max_bytes'], null, null, $a['allow_insecure'], $a['allowed_roots'], $a['allow_private_network']),
            reducer: new OpenApiReducer($redactor, $a['summary_cap'], $a['base_path']),
            cache: new ApiSpecCache($state, $clock ?? new SystemClock(), $a['ttl']),
            service: $config->stateKey,
        );
    }

    /** What Watcher takes: builds the reader per run, with that run's state (a dry run's wrapper) and redactor. @return \Closure(StateStore, Redactor): ?ApiContextReader */
    public static function apiContext(WatchConfig $config, Client $http, ?ClockInterface $clock = null): Closure
    {
        return static fn (StateStore $state, Redactor $redactor): ?ApiContextReader => self::apiContextReader(config: $config, http: $http, state: $state, redactor: $redactor, clock: $clock);
    }

    public static function gate(WatchConfig $c): RunGate
    {
        return RunGate::fromSchedule($c->schedule);
    }

    /** Liveness record reader/writer; every secret of the config is masked in a stored failure reason. */
    public static function heartbeat(WatchConfig $c, StateStore $state, ClockInterface $clock, ?LoggerInterface $log = null): Heartbeat
    {
        return new Heartbeat(
            $state,
            $clock,
            $c->stateKey,
            new \DateTimeZone($c->schedule['timezone']),
            $c->state['retention'],
            $log,
            $c->secrets(),
        );
    }

    /**
     * Collaborators for `watcher check`, built without running the pipeline (no Analyzer: `check` never bills).
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfig the caller reports it with {@see Doctor::configFailure()}
     */
    public static function doctor(array $config, ?Client $http = null, ?ClockInterface $clock = null): Doctor
    {
        $clock ??= new SystemClock();
        if (WatchConfig::masterSwitchOff($config)) {
            // Master switch first: a broken config is not validated; no source, state, catalog or notifier is built.
            return Doctor::switchedOff($clock);
        }
        $c = WatchConfig::fromArray(WatchConfig::withoutFleet($config));
        if (!$c->enabled) {
            return new Doctor($c, RefusedAccess::source(), RefusedAccess::state(), null, RefusedAccess::notifier(), $clock);
        }
        $http ??= self::http();
        $state = StateFactory::state($c, $clock);
        $probe = $c->observe->anyEnabled() ? ObserveFactory::observe($c, $clock, null, new ObserveCounters($state, $c->stateKey, $c->state['retention']), probe: true) : null;

        return new Doctor($c, SourceFactory::source($c, $http, $clock), $state, self::modelCatalog($c, $http), self::notifier($http, $c->schedule['lock_ttl']), $clock, observe: $probe, metrics: SourceFactory::metricSource($c, $http), issues: SourceFactory::issueSource($c, $http, $clock), apiContext: self::apiContextReader(config: $c, http: $http, state: $state, redactor: new Redactor(new Normalizer(extraPatterns: $c->redactionPatterns), $c->redactionPatterns), clock: $clock));
    }

    /**
     * For `watcher state:get`: reads one logical key of the configured store, redacted. No other collaborator is built.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfig
     */
    public static function stateInspector(array $config, ?ClockInterface $clock = null): StateInspector
    {
        $c = WatchConfig::fromArray(WatchConfig::withoutFleet($config));
        $normalizer = new Normalizer($c->on('dependencies') ? array_map('strval', array_keys($c->dependencies)) : [], $c->dependencyDomains, extraPatterns: $c->redactionPatterns);
        $pathMap = is_array($c->repo['path_map'] ?? null) ? array_map('strval', $c->repo['path_map']) : [];

        return new StateInspector(StateFactory::state($c, $clock ?? new SystemClock()), new Redactor($normalizer, $c->redactionPatterns, $pathMap), $c->stateKey, $c->secrets());
    }

    /**
     * For `watcher status`. Never throws for an unreadable store (that is `unknown`); InvalidConfig only for a bad config.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfig
     */
    public static function heartbeatStatus(array $config, ?ClockInterface $clock = null, ?LoggerInterface $log = null): HeartbeatStatus
    {
        $clock ??= new SystemClock();
        if (WatchConfig::masterSwitchOff($config)) {
            // Master switch first: a broken config is not validated and no store is read; the stale limit is not known here.
            return HeartbeatStatus::disabled(0, $clock->now());
        }
        $c = WatchConfig::fromArray(WatchConfig::withoutFleet($config));
        if (!$c->enabled || !$c->on('heartbeat')) {
            return HeartbeatStatus::disabled($c->heartbeat['stale_after'], $clock->now());
        }

        $state = StateFactory::state($c, $clock);
        $status = self::heartbeat($c, $state, $clock, $log)->status($c->heartbeat['stale_after']);
        if (!$c->observe->anyEnabled()) {
            return $status;
        }
        $failed = (new ObserveCounters($state, $c->stateKey, $c->state['retention']))->unseenSinkFailures();

        return $status->withWarnings(array_map(static fn (string $sink): string => 'sink_failed:' . $sink, $failed));
    }


    public static function source(WatchConfig $c, Client $http, ?ClockInterface $clock = null): LogSource
    {
        return SourceFactory::source($c, $http, $clock);
    }

    /**
     * The metrics source of spec 009, or null when the section is absent or `enabled: false`.
     *
     * @param (Closure(): ?string)|null $stop returns a reason once the run must wind down (`RunGuard::reason()`)
     */
    public static function metricSource(WatchConfig $c, ClientInterface $http, ?Closure $stop = null): ?MetricSource
    {
        return SourceFactory::metricSource($c, $http, $stop);
    }

    /**
     * The Sentry issue source of spec 009, or null when the section is absent or `enabled: false`.
     *
     * @param (Closure(): ?string)|null $stop returns a reason once the run must wind down (`RunGuard::reason()`)
     */
    public static function issueSource(WatchConfig $c, ClientInterface $http, ClockInterface $clock, ?Closure $stop = null): ?IssueSource
    {
        return SourceFactory::issueSource($c, $http, $clock, $stop);
    }

    public static function state(WatchConfig $c, ClockInterface $clock, ?LoggerInterface $log = null): StateStore
    {
        return StateFactory::state($c, $clock, $log);
    }

    /**
     * Seconds a Redis call may wait for a reply (default 5, A4-15); a hung server must not hold a run for the 60 s socket default per key.
     *
     * @param array<string, mixed> $r
     */
    public static function redisReadTimeout(array $r): float
    {
        return StateFactory::redisReadTimeout($r);
    }

    /**
     * `state.redis.client`: auto (ext-redis when loaded, else Predis), phpredis or predis. Throws when the choice is not installed.
     *
     * @return 'phpredis'|'predis'
     */
    public static function pickRedisClient(string $mode, bool $extensionLoaded, bool $predisInstalled): string
    {
        return StateFactory::pickRedisClient($mode, $extensionLoaded, $predisInstalled);
    }

    /** An injected client: a \Redis, anything already speaking RedisClient, or else a Predis client. */
    public static function wrapRedisInstance(#[\SensitiveParameter] object $instance): RedisClient
    {
        return StateFactory::wrapRedisInstance($instance);
    }

    public static function analyzer(WatchConfig $c): Analyzer
    {
        return AnalyzerFactory::analyzer($c);
    }

    /**
     * The sinks of the `observe` section as one dispatcher, or null when none is enabled (the package then behaves as before).
     *
     * @param bool $probe for `check --post-test`: leaves out the Prometheus file, which a one-event probe would overwrite
     * @param resource|null $logStream test seam: the stream the log sink writes to instead of stderr/stdout
     * @param bool $stdoutToStderr a `--json` call keeps its stdout one parseable line: a log sink aimed at stdout writes to stderr
     */
    public static function observe(WatchConfig $c, ClockInterface $clock, ?LoggerInterface $log, ObserveCounters $counters, ?Client $http = null, bool $stdoutToStderr = false, $logStream = null, bool $probe = false): ?SinkDispatcher
    {
        return ObserveFactory::observe($c, $clock, $log, $counters, $http, $stdoutToStderr, $logStream, $probe);
    }

    /** Where the log sink writes; a `--json` call keeps stdout for its one summary line. */
    public static function logTarget(string $stream, bool $json): string
    {
        return ObserveFactory::logTarget($stream, $json);
    }

    /** @return array{allow_redirects: false, connect_timeout: int} an event endpoint that redirects is an answer, never followed */
    public static function observeHttpOptions(int $timeout): array
    {
        return ObserveFactory::observeHttpOptions($timeout);
    }
}
