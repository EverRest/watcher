<?php

declare(strict_types=1);

namespace Watcher\Config;

use Closure;
use GuzzleHttp\Client;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Redis;
use RedisException;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Notify\Notifier;
use Watcher\Notify\SlackWebhookNotifier;
use Watcher\Source\LogSource;
use Watcher\Source\DatadogSource;
use Watcher\Source\LokiSource;
use Watcher\State\FileStateStore;
use Watcher\State\PdoStateStore;
use Watcher\State\RedisStateStore;
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

    /** @return array{timeout: int, connect_timeout: int} a long analysis reply can take well over 30 s */
    public static function analyzerHttpOptions(): array
    {
        return ['timeout' => 180, 'connect_timeout' => 10];
    }

    public static function source(WatchConfig $c, Client $http): LogSource
    {
        if ($c->source['driver'] === 'datadog') {
            /** @var array<string, string> $dd */
            $dd = $c->source['datadog'];

            return new DatadogSource($http, $dd['site'] ?? 'datadoghq.com', $dd['api_key'], $dd['app_key']);
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
        );
    }

    public static function state(WatchConfig $c, ClockInterface $clock): StateStore
    {
        /** @var array<string, mixed> $st */
        $st = $c->state;

        return match ($st['driver']) {
            'redis' => self::lazy(static fn (): StateStore => new RedisStateStore(self::redis(is_array($st['redis'] ?? null) ? $st['redis'] : []), $clock)),
            // The table is never created here: run PdoStateStore::createTableSql($driver, $table) once, in a migration.
            'pdo' => new PdoStateStore(self::pdo($st['pdo']), $clock, (string) ($st['pdo']['table'] ?? 'watcher_state')),
            default => new FileStateStore((string) $st['file']['path'], $clock),
        };
    }

    /** @param array<string, mixed> $r */
    private static function redis(array $r): Redis
    {
        if (($r['instance'] ?? null) instanceof Redis) {
            return $r['instance'];
        }
        $redis = new Redis();
        try {
            $redis->connect((string) ($r['host'] ?? '127.0.0.1'), (int) ($r['port'] ?? 6379), 5.0);
            if (isset($r['password'])) {
                $redis->auth((string) $r['password']);
            }
            $redis->select((int) ($r['database'] ?? 0));
        } catch (RedisException) {
            throw new RuntimeException('state.redis: cannot connect or authenticate'); // never echo the driver message (may carry the password)
        }

        return $redis;
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
        return new class ($make) implements StateStore {
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

        return new AnthropicAnalyzer((string) $c->analysis['api_key'], new Client(self::analyzerHttpOptions()));
    }

    public static function notifier(Client $http): Notifier
    {
        return new SlackWebhookNotifier($http);
    }

    private static function unavailable(string $key, string $value): InvalidConfig
    {
        return new InvalidConfig("$key '$value' is not available yet");
    }
}
