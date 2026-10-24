<?php

declare(strict_types=1);

namespace Watcher\Config;

use Closure;
use GuzzleHttp\Client;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Notify\DiscordNotifier;
use Watcher\Notify\Notifier;
use Watcher\Notify\RoutingNotifier;
use Watcher\Operate\AnthropicModelCatalog;
use Watcher\Operate\Doctor;
use Watcher\Operate\Heartbeat;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\ModelCatalog;
use Watcher\Schedule\SystemClock;
use Watcher\Notify\SlackWebhookNotifier;
use Watcher\Notify\TelegramNotifier;
use Watcher\Source\LogSource;
use Watcher\Source\DatadogSource;
use Watcher\Source\LokiSource;
use Watcher\State\FileStateStore;
use Watcher\State\PdoStateStore;
use Watcher\State\Redis\PhpRedisClient;
use Watcher\State\Redis\PredisClient;
use Watcher\State\Redis\RedisClient;
use Watcher\State\RedisStateStore;
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
     * A long analysis reply can take well over 30 s, but a model call (two sequential attempts, no SDK retry) must stay within
     * a quarter of lock_ttl, so a second run cannot start while this one is still analysing: timeout = lock_ttl / 4 / 2, within 10..180 s.
     * The lock itself is not renewed (StateStore has no renew); the router makes at most 2 + escalation_cap model calls.
     *
     * @return array{timeout: int, connect_timeout: int}
     */
    public static function analyzerHttpOptions(int $lockTtl = 900): array
    {
        return ['timeout' => max(10, min(180, intdiv($lockTtl, 8))), 'connect_timeout' => 10];
    }

    /** @return array{maxRetries: int} an SDK retry would multiply the worst case of a call; the next scheduled trigger is the retry */
    public static function analyzerRequestOptions(): array
    {
        return ['maxRetries' => 0];
    }

    public static function source(WatchConfig $c, Client $http, ?ClockInterface $clock = null): LogSource
    {
        try {
            return self::buildSource($c, $http, $clock);
        } catch (\InvalidArgumentException) {
            throw new InvalidConfig('source: invalid settings'); // not chained: the message may carry a URL or key
        }
    }

    private static function buildSource(WatchConfig $c, Client $http, ?ClockInterface $clock): LogSource
    {
        if ($c->source['driver'] === 'datadog') {
            /** @var array<string, mixed> $dd */
            $dd = $c->source['datadog'];

            return new DatadogSource(
                $http,
                (string) ($dd['site'] ?? 'datadoghq.com'),
                (string) $dd['api_key'],
                (string) $dd['app_key'],
                clock: $clock,
                levelMode: $c->source['level_mode'] === 'all' ? 'all' : 'status',
                newestFirst: ($dd['newest_first'] ?? false) === true,
            );
        }
        /** @var array<string, mixed> $loki */
        $loki = $c->source['loki'];
        /** @var array<string, string>|null $auth */
        $auth = $loki['auth'] ?? null;
        $tenant = $loki['tenant'] ?? null;

        return new LokiSource(
            $http,
            (string) $loki['url'],
            ($auth['type'] ?? null) === 'bearer' ? $auth['token'] : null,
            ($auth['type'] ?? null) === 'basic' ? $auth['user'] : null,
            ($auth['type'] ?? null) === 'basic' ? $auth['password'] : null,
            is_string($tenant) ? $tenant : null,
            levelMode: (string) $c->source['level_mode'],
            allowInsecure: ($loki['allow_insecure'] ?? false) === true,
        );
    }

    public static function state(WatchConfig $c, ClockInterface $clock, ?LoggerInterface $log = null): StateStore
    {
        /** @var array<string, mixed> $st */
        $st = $c->state;

        return match ($st['driver']) {
            'redis' => self::lazy(static fn (): StateStore => new RedisStateStore(
                self::redisClient(is_array($st['redis'] ?? null) ? $st['redis'] : []),
                $clock,
                'watcher:',
                is_int($st['redis']['compress_min_bytes'] ?? null) ? $st['redis']['compress_min_bytes'] : 512,
                $log,
            )),
            // The table is never created here: run PdoStateStore::createTableSql($driver, $table) once, in a migration.
            // Lazy like Redis: a connect failure on a trigger that is not due must not fail it.
            'pdo' => self::lazy(static fn (): StateStore => new PdoStateStore(self::pdo($st['pdo']), $clock, (string) ($st['pdo']['table'] ?? 'watcher_state'))),
            default => new FileStateStore((string) $st['file']['path'], $clock, $c->schedule['lock_ttl']),
        };
    }

    /** Seconds a Redis call may wait for a reply (default 5, A4-15); a hung server must not hold a run for the 60 s socket default per key. */
    /** @param array<string, mixed> $r */
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
    public static function wrapRedisInstance(object $instance): RedisClient
    {
        return match (true) {
            $instance instanceof RedisClient => $instance,
            $instance instanceof Redis => new PhpRedisClient($instance),
            default => new PredisClient($instance),
        };
    }

    /** @param array<string, mixed> $r */
    private static function redisClient(array $r): RedisClient
    {
        if (is_object($r['instance'] ?? null)) {
            return self::wrapRedisInstance($r['instance']);
        }
        /** @var class-string $predisClass predis/predis is only a `suggest`: phpstan must not look for it */
        $predisClass = 'Predis\\Client';
        $pick = self::pickRedisClient(is_string($r['client'] ?? null) ? $r['client'] : 'auto', class_exists(Redis::class), class_exists($predisClass));
        if ($pick === 'predis') {
            try {
                return new PredisClient(new $predisClass(array_filter([
                    'scheme' => str_starts_with((string) ($r['host'] ?? ''), 'tls://') ? 'tls' : 'tcp',
                    'host' => (string) preg_replace('~^tls://~', '', (string) ($r['host'] ?? '127.0.0.1')),
                    'port' => (int) ($r['port'] ?? 6379),
                    'database' => (int) ($r['database'] ?? 0),
                    'username' => isset($r['username'], $r['password']) ? (string) $r['username'] : null,
                    'password' => isset($r['password']) ? (string) $r['password'] : null,
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
                $redis->auth(isset($r['username']) ? [(string) $r['username'], (string) $r['password']] : (string) $r['password']);
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
        try {
            return new PDO((string) $p['dsn'], $p['username'] ?? null, $p['password'] ?? null);
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
        return new class ($make) implements StateStore, RenewableLock {
            private ?StateStore $store = null;

            public function __construct(private readonly Closure $make)
            {
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

            private function store(): StateStore
            {
                return $this->store ??= ($this->make)();
            }
        };
    }

    public static function analyzer(WatchConfig $c): Analyzer
    {
        if ($c->analysis['provider'] !== 'anthropic') {
            throw self::unavailable('analysis.provider', (string) $c->analysis['provider']);
        }

        return new AnthropicAnalyzer(
            (string) $c->analysis['api_key'],
            new Client(self::analyzerHttpOptions($c->schedule['lock_ttl'])),
            null,
            self::analyzerRequestOptions(),
            $c->on('advice'),
        );
    }

    /** $lockTtl bounds the rate-limit wait of the chat notifiers: min(60, max(1, lockTtl / 10)) seconds per message. */
    public static function notifier(Client $http, int $lockTtl = 900): Notifier
    {
        $budget = min(60, max(1, intdiv($lockTtl, 10)));

        return new RoutingNotifier(new SlackWebhookNotifier($http), new TelegramNotifier($http, $budget), new DiscordNotifier($http, $budget));
    }

    /** Models-list probe for `check`; null for a provider without one. Makes no call by itself. */
    public static function modelCatalog(WatchConfig $c, Client $http): ?ModelCatalog
    {
        return $c->analysis['provider'] === 'anthropic' ? new AnthropicModelCatalog($http, (string) $c->analysis['api_key']) : null;
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
        $c = WatchConfig::fromArray($config);
        $clock ??= new SystemClock();
        $http ??= self::http();

        return new Doctor($c, self::source($c, $http, $clock), self::state($c, $clock), self::modelCatalog($c, $http), self::notifier($http, $c->schedule['lock_ttl']), $clock, getenv('KUBERNETES_SERVICE_HOST') !== false && getenv('KUBERNETES_SERVICE_HOST') !== '');
    }

    /**
     * For `watcher status`. Never throws for an unreadable store (that is `unknown`); InvalidConfig only for a bad config.
     *
     * @param array<string, mixed> $config
     * @throws InvalidConfig
     */
    public static function heartbeatStatus(array $config, ?ClockInterface $clock = null, ?LoggerInterface $log = null): HeartbeatStatus
    {
        $c = WatchConfig::fromArray($config);
        $clock ??= new SystemClock();
        if (!$c->enabled || !$c->on('heartbeat')) {
            return HeartbeatStatus::disabled($c->heartbeat['stale_after'], $clock->now());
        }

        return self::heartbeat($c, self::state($c, $clock), $clock, $log)->status($c->heartbeat['stale_after']);
    }

    private static function unavailable(string $key, string $value): InvalidConfig
    {
        return new InvalidConfig("$key '$value' is not available yet");
    }
}
