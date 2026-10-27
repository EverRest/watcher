<?php

declare(strict_types=1);

namespace Watcher\Config;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Analyze\AnthropicSdkProvider;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\ModelProvider;
use Watcher\Analyze\OpenAiProvider;
use Watcher\Analyze\ProviderRegistry;
use Watcher\Issues\IssueSource;
use Watcher\Issues\SentryIssueSource;
use Watcher\Metrics\MetricSource;
use Watcher\Metrics\PrometheusSource;
use Watcher\ApiContext\ApiContextReader;
use Watcher\ApiContext\ApiSpecCache;
use Watcher\ApiContext\OpenApiReducer;
use Watcher\ApiContext\OpenApiSource;
use Watcher\Notify\DiscordNotifier;
use Watcher\Observe\EventSanitizer;
use Watcher\Observe\FileSink;
use Watcher\Observe\HttpSink;
use Watcher\Observe\LogSink;
use Watcher\Observe\ObserveCounters;
use Watcher\Observe\PrometheusSink;
use Watcher\Observe\SinkDispatcher;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Source\Retry;
use Watcher\Notify\Notifier;
use Watcher\Notify\RoutingNotifier;
use Watcher\Operate\AnthropicModelCatalog;
use Watcher\Operate\Doctor;
use Watcher\Operate\Heartbeat;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\ModelCatalog;
use Watcher\Operate\RefusedAccess;
use Watcher\Schedule\SystemClock;
use Watcher\Notify\SlackWebhookNotifier;
use Watcher\Notify\TelegramNotifier;
use Watcher\Source\Kubernetes\KubeApi;
use Watcher\Source\KubernetesEventsSource;
use Watcher\Source\LogSource;
use Watcher\Source\DatadogSource;
use Watcher\Source\LokiSource;
use Watcher\State\FileStateStore;
use Watcher\State\PdoStateStore;
use Watcher\State\Purgeable;
use Watcher\Operate\StateInspector;
use Watcher\Schedule\RunGate;
use Watcher\State\Redis\PhpRedisClient;
use Watcher\State\Redis\PredisClient;
use Watcher\State\Redis\RedisClient;
use Watcher\State\RedisStateStore;
use Watcher\State\UnreadableCounter;
use Watcher\State\RenewableLock;
use RuntimeException;
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

    /** @param array<string, KubeApi> $apis shared by the entries of a fleet (pod events only) */
    public static function source(WatchConfig $c, Client $http, ?ClockInterface $clock = null, array &$apis = []): LogSource
    {
        try {
            return self::buildSource($c, $http, $clock, $apis);
        } catch (\InvalidArgumentException) {
            throw new InvalidConfig('source: invalid settings'); // not chained: the message may carry a URL or key
        }
    }

    /** @param array<string, KubeApi> $apis */
    private static function buildSource(WatchConfig $c, Client $http, ?ClockInterface $clock, array &$apis): LogSource
    {
        if ($c->source['driver'] === 'kubernetes') {
            return self::kubernetesSource($c, $http, $clock ?? new SystemClock(), $apis);
        }
        if ($c->source['driver'] === 'datadog') {
            /** @var array<string, mixed> $dd */
            $dd = $c->source['datadog'];

            return new DatadogSource(
                $http,
                (string) ($dd['site'] ?? 'datadoghq.com'),
                WatchConfig::reveal($dd['api_key']),
                WatchConfig::reveal($dd['app_key']),
                clock: $clock,
                levelMode: $c->source['level_mode'] === 'all' ? 'all' : 'status',
                newestFirst: ($dd['newest_first'] ?? false) === true,
                maxResponseBytes: self::maxResponseBytes($c),
            );
        }
        /** @var array<string, mixed> $loki */
        $loki = $c->source['loki'];
        /** @var array<string, mixed>|null $auth */
        $auth = $loki['auth'] ?? null;
        $tenant = $loki['tenant'] ?? null;

        return new LokiSource(
            $http,
            (string) $loki['url'],
            ($auth['type'] ?? null) === 'bearer' ? WatchConfig::reveal($auth['token']) : null,
            ($auth['type'] ?? null) === 'basic' ? (string) $auth['user'] : null,
            ($auth['type'] ?? null) === 'basic' ? WatchConfig::reveal($auth['password']) : null,
            is_string($tenant) ? $tenant : null,
            levelMode: (string) $c->source['level_mode'],
            allowInsecure: ($loki['allow_insecure'] ?? false) === true,
            maxResponseBytes: self::maxResponseBytes($c),
        );
    }

    /** Wall-clock seconds one metrics or Sentry call (retries and pages included) may take; both sources share this one figure. */
    public const SOURCE_BUDGET_SECONDS = 60;

    /**
     * The metrics source of spec 009, or null when the section is absent or `enabled: false`.
     *
     * @param (Closure(): ?string)|null $stop returns a reason once the run must wind down (`RunGuard::reason()`)
     */
    public static function metricSource(WatchConfig $c, ClientInterface $http, ?Closure $stop = null): ?MetricSource
    {
        $m = $c->metrics;
        if ($m === null || !$m->enabled) {
            return null;
        }

        return new PrometheusSource(
            $http,
            $m->baseUrl,
            $m->token(),
            $m->type === 'grafana' ? $m->datasourceUid : null,
            $m->labelAllowlist,
            $m->maxPoints,
            allowInsecure: $m->allowInsecure,
            budgetSeconds: self::SOURCE_BUDGET_SECONDS,
            stop: $stop,
            allowPrivateNetwork: $m->allowPrivateNetwork,
        );
    }

    /**
     * The Sentry issue source of spec 009, or null when the section is absent or `enabled: false`.
     *
     * @param (Closure(): ?string)|null $stop returns a reason once the run must wind down (`RunGuard::reason()`)
     */
    public static function issueSource(WatchConfig $c, ClientInterface $http, ClockInterface $clock, ?Closure $stop = null): ?IssueSource
    {
        $s = $c->sentry;
        if ($s === null || !$s->enabled) {
            return null;
        }

        return new SentryIssueSource(
            $http,
            $s->baseUrl,
            $s->organization,
            $s->project,
            $s->token(),
            $s->maxIssues,
            clock: $clock,
            allowInsecure: $s->allowInsecure,
            budgetSeconds: self::SOURCE_BUDGET_SECONDS,
            stop: $stop,
            allowPrivateNetwork: $s->allowPrivateNetwork,
        );
    }

    private static function maxResponseBytes(WatchConfig $c): int
    {
        return (is_int($c->source['max_response_mb'] ?? null) ? $c->source['max_response_mb'] : 16) * 1024 * 1024;
    }

    /**
     * The extra pod-events source declared beside `source` (spec 008), or null: nothing declared, the flag is off, or
     * Kubernetes already is the main source. $apis is shared by the entries of a fleet: the same cluster and credentials
     * share one client (and its short list memo), different credentials never do.
     *
     * @param array<string, KubeApi> $apis
     */
    public static function events(WatchConfig $c, Client $http, ClockInterface $clock, array &$apis = []): ?KubernetesEventsSource
    {
        $k = $c->kubernetes;
        if ($k === null || !$c->on('kubernetes') || !$k->events || $k->asSource) {
            return null;
        }

        return self::kubernetesSource($c, $http, $clock, $apis);
    }

    /** @param array<string, KubeApi> $apis */
    private static function kubernetesSource(WatchConfig $c, Client $http, ClockInterface $clock, array &$apis): KubernetesEventsSource
    {
        $k = $c->kubernetes ?? throw new InvalidConfig('kubernetes: invalid settings');
        $api = $apis[$k->credentialKey()] ??= new KubeApi($http, $k, $clock);
        $window = (new \DateTimeImmutable('@0'))->add(new \DateInterval($c->schedule['window']))->getTimestamp();

        return new KubernetesEventsSource($api, $k, $clock, new Normalizer(extraPatterns: $c->redactionPatterns), $c->stateKey, $window, $c->state['retention']);
    }

    public static function state(WatchConfig $c, ClockInterface $clock, ?LoggerInterface $log = null): StateStore
    {
        /** @var array<string, mixed> $st */
        $st = $c->state;

        return match ($st['driver']) {
            'redis' => self::lazy(static fn (): StateStore => new RedisStateStore(
                self::redisClient(is_array($st['redis'] ?? null) ? $st['redis'] : []),
                $clock,
                is_string($st['redis']['prefix'] ?? null) ? $st['redis']['prefix'] : 'watcher:',
                is_int($st['redis']['compress_min_bytes'] ?? null) ? $st['redis']['compress_min_bytes'] : 512,
                $log,
            )),
            // The table is never created here: run PdoStateStore::createTableSql($driver, $table) once, in a migration.
            // Lazy like Redis: a connect failure on a trigger that is not due must not fail it.
            'pdo' => self::lazy(static fn (): StateStore => new PdoStateStore(self::pdo($st['pdo']), $clock, (string) ($st['pdo']['table'] ?? 'watcher_state'))),
            default => new FileStateStore((string) $st['file']['path'], $clock, $c->schedule['lock_ttl']),
        };
    }

    /**
     * Seconds a Redis call may wait for a reply (default 5, A4-15); a hung server must not hold a run for the 60 s socket default per key.
     *
     * @param array<string, mixed> $r
     */
    public static function redisReadTimeout(array $r): float
    {
        $t = $r['read_timeout'] ?? null;

        return (is_int($t) || is_float($t)) && $t > 0 ? (float) $t : 5.0;
    }

    /**
     * `state.redis.client`: auto (ext-redis when loaded, else Predis), phpredis or predis. Throws when the choice is not installed.
     *
     * @return 'phpredis'|'predis'
     */
    public static function pickRedisClient(string $mode, bool $extensionLoaded, bool $predisInstalled): string
    {
        if ($mode === 'phpredis' && !$extensionLoaded) {
            throw new RuntimeException('state.redis.client=phpredis needs ext-redis');
        }
        if ($mode === 'predis' && !$predisInstalled) {
            throw new RuntimeException('state.redis.client=predis needs predis/predis (composer require predis/predis)');
        }
        if ($mode === 'auto' && !$extensionLoaded && !$predisInstalled) {
            throw new RuntimeException('state.redis: install ext-redis or predis/predis');
        }

        return $mode === 'predis' || ($mode === 'auto' && !$extensionLoaded) ? 'predis' : 'phpredis';
    }

    /** An injected client: a \Redis, anything already speaking RedisClient, or else a Predis client. */
    public static function wrapRedisInstance(#[\SensitiveParameter] object $instance): RedisClient
    {
        return match (true) {
            $instance instanceof RedisClient => $instance,
            $instance instanceof Redis => new PhpRedisClient($instance),
            default => new PredisClient($instance),
        };
    }

    /**
     * predis/predis is only a `suggest`: the name comes from a method so phpstan does not look for the class.
     */
    private static function predisClass(): string
    {
        return 'Predis\\Client';
    }

    /**
     * #[\SensitiveParameter] (PHP 8.2+, ignored on 8.1) keeps `$r`, password included, out of exception traces.
     *
     * @param array<string, mixed> $r
     */
    private static function redisClient(#[\SensitiveParameter] array $r): RedisClient
    {
        if (is_object($r['instance'] ?? null)) {
            return self::wrapRedisInstance($r['instance']);
        }
        $predisClass = self::predisClass();
        $pick = self::pickRedisClient(is_string($r['client'] ?? null) ? $r['client'] : 'auto', class_exists(Redis::class), class_exists($predisClass));
        if ($pick === 'predis') {
            try {
                return new PredisClient(new $predisClass(array_filter([
                    'scheme' => str_starts_with((string) ($r['host'] ?? ''), 'tls://') ? 'tls' : 'tcp',
                    'host' => (string) preg_replace('~^tls://~', '', (string) ($r['host'] ?? '127.0.0.1')),
                    'port' => (int) ($r['port'] ?? 6379),
                    'database' => (int) ($r['database'] ?? 0),
                    'username' => isset($r['username'], $r['password']) ? (string) $r['username'] : null,
                    'password' => isset($r['password']) ? WatchConfig::reveal($r['password']) : null,
                    'timeout' => 5.0,
                    'read_write_timeout' => self::redisReadTimeout($r),
                ], static fn (mixed $v): bool => $v !== null)));
            } catch (\Throwable) {
                throw new RuntimeException('state.redis: cannot create the predis client'); // never echo the driver message (may carry the password)
            }
        }
        $redis = new Redis();
        try {
            $redis->connect((string) ($r['host'] ?? '127.0.0.1'), (int) ($r['port'] ?? 6379), 5.0, null, 0, self::redisReadTimeout($r));
            if (isset($r['password'])) {
                // Redis 6 ACL: with a username the pair is sent; use a `tls://` host for TLS.
                $password = WatchConfig::reveal($r['password']);
                $redis->auth(isset($r['username']) ? [(string) $r['username'], $password] : $password);
            }
            $redis->select((int) ($r['database'] ?? 0));
        } catch (RedisException) {
            throw new RuntimeException('state.redis: cannot connect or authenticate'); // never echo the driver message (may carry the password)
        }

        return new PhpRedisClient($redis);
    }

    /** @param array<string, mixed> $p */
    private static function pdo(array $p): PDO
    {
        $dsn = WatchConfig::reveal($p['dsn']);
        $options = [];
        // MySQL reports changed rows, not matched; put/renew treat rowCount 0 as a miss (P-M1).
        if (str_starts_with($dsn, 'mysql:')) {
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        }
        try {
            return new PDO($dsn, isset($p['username']) ? WatchConfig::reveal($p['username']) : null, isset($p['password']) ? WatchConfig::reveal($p['password']) : null, $options);
        } catch (PDOException) {
            throw new InvalidConfig('state.pdo: cannot connect'); // not chained: the driver message can carry the DSN or password
        }
    }

    /**
     * Connects on first use, so a trigger that is not due never reaches Redis (Watcher::run reaches the store only after the RunGate).
     * A failed connect is retried by the next access.
     */
    private static function lazy(Closure $make): StateStore
    {
        return new class ($make) implements StateStore, Purgeable, RenewableLock, UnreadableCounter {
            /** @var array<int, Closure(): StateStore> the factory closes over the connection settings (the Redis password): kept out of the object so no dump of it shows them */
            private static array $makers = [];

            private ?StateStore $store = null;

            public function __construct(Closure $make)
            {
                self::$makers[spl_object_id($this)] = $make;
            }

            public function __destruct()
            {
                unset(self::$makers[spl_object_id($this)]);
            }

            /** @return array<string, string> */
            public function __debugInfo(): array
            {
                return ['store' => $this->store === null ? '<not connected>' : '<connected>'];
            }

            public function get(string $key): ?array
            {
                return $this->store()->get($key);
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
                $this->store()->put($key, $value, $ttlSeconds);
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                return $this->store()->acquire($key, $owner, $ttlSeconds);
            }

            public function release(string $key, string $owner): void
            {
                $this->store()->release($key, $owner);
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                return $this->store()->increment($key, $by, $ttlSeconds);
            }

            public function renew(string $key, string $owner, int $ttlSeconds): bool
            {
                $store = $this->store();

                return $store instanceof RenewableLock && $store->renew($key, $owner, $ttlSeconds);
            }

            public function unreadableEntries(): int
            {
                return $this->store instanceof UnreadableCounter ? $this->store->unreadableEntries() : 0; // never connects just to count
            }

            public function purge(int $maxFiles = 5000, float $maxSeconds = 2.0): int
            {
                $store = $this->store();

                return $store instanceof Purgeable ? $store->purge($maxFiles, $maxSeconds) : 0;
            }

            private function store(): StateStore
            {
                return $this->store ??= (self::$makers[spl_object_id($this)])();
            }
        };
    }

    public static function analyzer(WatchConfig $c): Analyzer
    {
        $options = self::analyzerHttpOptions($c->schedule['lock_ttl']);
        $http = new Client($options);

        $providers = [];
        foreach (array_unique(array_values($c->analysis['step_providers'])) as $name) {
            $providers[$name] = self::modelProvider($name, $c, $http, $options['timeout']);
        }
        return new AnthropicAnalyzer(new ProviderRegistry($providers, $c->analysis['step_providers']), $c->on('advice'));
    }

    /** The adapter for one provider name, with its key, base URL and retries from configuration (spec 016). */
    private static function modelProvider(string $name, WatchConfig $c, Client $http, int $timeout): ModelProvider
    {
        $key = WatchConfig::reveal($c->analysis['api_keys'][$name]);
        $opt = $c->analysis['providers_options'][$name];

        return match ($name) {
            'anthropic' => new AnthropicSdkProvider($http, $key, $opt['base_url'], $timeout, $opt['retries']),
            'openai' => new OpenAiProvider($http, $key, $opt['base_url'], $timeout, $opt['retries']),
            'gemini' => new GeminiProvider($http, $key, $opt['base_url'], $timeout, $opt['retries']),
            default => throw new \LogicException("no adapter for provider $name"),
        };
    }

    /** $lockTtl bounds the rate-limit wait of the chat notifiers: min(60, max(1, lockTtl / 10)) seconds per message. */
    public static function notifier(Client $http, int $lockTtl = 900): Notifier
    {
        $timeout = min(60, max(1, intdiv($lockTtl, 10)));

        return new RoutingNotifier(new SlackWebhookNotifier($http, $timeout), new TelegramNotifier($http, $timeout), new DiscordNotifier($http, $timeout));
    }

    /** Where the log sink writes; a `--json` call keeps stdout for its one summary line. */
    public static function logTarget(string $stream, bool $json): string
    {
        return $stream === 'stdout' && !$json ? 'php://stdout' : 'php://stderr';
    }

    /** @return array{allow_redirects: false, connect_timeout: int} an event endpoint that redirects is an answer, never followed */
    public static function observeHttpOptions(int $timeout): array
    {
        return ['allow_redirects' => false, 'connect_timeout' => $timeout];
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
        $o = $c->observe;
        if (!$o->anyEnabled()) {
            return null;
        }
        $sinks = [];
        if ($o->log['enabled']) {
            $stream = $logStream ?? fopen(self::logTarget($o->log['stream'], $stdoutToStderr), 'w');
            if ($stream !== false) {
                $sinks['log'] = [new LogSink($stream), $o->log['include_text']];
            }
        }
        if ($o->file['enabled']) {
            $f = $o->file;
            $sinks['file'] = [new FileSink($f['path'], $f['rotate'], $f['max_bytes'], $f['max_files'], $f['retention_days'], $clock, $log), $f['include_text']];
        }
        $target = $o->httpTarget();
        if ($o->http['enabled'] && $target !== null) {
            $h = $o->http;
            $sinks['http'] = [new HttpSink($target, $http ?? new Client(self::observeHttpOptions($h['timeout'])), static fn (int $left, callable $sleeper): Retry => new Retry($sleeper, min(15, $left)), $h['batch_size'], $h['timeout'], $h['auth_header'], $h['auth_scheme'], $o->httpToken(), 15), $h['include_text']];
        }
        if ($o->prometheus['enabled'] && !$probe) {
            $p = $o->prometheus;
            $sinks['prometheus'] = [new PrometheusSink($p['dir'], $p['name'], $p['file_mode'], $clock, $counters->snapshot(...)), false];
        }
        $redactor = new Redactor(new Normalizer([], $c->dependencyDomains, extraPatterns: $c->redactionPatterns), $c->redactionPatterns);

        return new SinkDispatcher($sinks, new EventSanitizer($redactor), $log);
    }

    /** Models-list probe for `check`; null for a provider without one. Makes no call by itself. */
    public static function modelCatalog(WatchConfig $c, Client $http): ?ModelCatalog
    {
        // Only Anthropic has a model list here; it is probed when any step uses it, with that step's key.
        return in_array('anthropic', $c->analysis['step_providers'], true) ? new AnthropicModelCatalog($http, WatchConfig::reveal($c->analysis['api_keys']['anthropic'])) : null;
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
        $state = self::state($c, $clock);
        $probe = $c->observe->anyEnabled() ? self::observe($c, $clock, null, new ObserveCounters($state, $c->stateKey, $c->state['retention']), probe: true) : null;

        return new Doctor($c, self::source($c, $http, $clock), $state, self::modelCatalog($c, $http), self::notifier($http, $c->schedule['lock_ttl']), $clock, events: self::events($c, $http, $clock), kubernetes: getenv('KUBERNETES_SERVICE_HOST') !== false && getenv('KUBERNETES_SERVICE_HOST') !== '', observe: $probe, metrics: self::metricSource($c, $http), issues: self::issueSource($c, $http, $clock), apiContext: self::apiContextReader(config: $c, http: $http, state: $state, redactor: new Redactor(new Normalizer(extraPatterns: $c->redactionPatterns), $c->redactionPatterns), clock: $clock));
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

        return new StateInspector(self::state($c, $clock ?? new SystemClock()), new Redactor($normalizer, $c->redactionPatterns, $pathMap), $c->stateKey, $c->secrets());
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

        $state = self::state($c, $clock);
        $status = self::heartbeat($c, $state, $clock, $log)->status($c->heartbeat['stale_after']);
        if (!$c->observe->anyEnabled()) {
            return $status;
        }
        $failed = (new ObserveCounters($state, $c->stateKey, $c->state['retention']))->unseenSinkFailures();

        return $status->withWarnings(array_map(static fn (string $sink): string => 'sink_failed:' . $sink, $failed));
    }

}
