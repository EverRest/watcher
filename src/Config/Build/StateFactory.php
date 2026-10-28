<?php

declare(strict_types=1);

namespace Watcher\Config\Build;

use Closure;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use RuntimeException;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Schedule\RunGate;
use Watcher\State\FileStateStore;
use Watcher\State\PdoStateStore;
use Watcher\State\Purgeable;
use Watcher\State\RedisStateStore;
use Watcher\State\Redis\PhpRedisClient;
use Watcher\State\Redis\PredisClient;
use Watcher\State\Redis\RedisClient;
use Watcher\State\RenewableLock;
use Watcher\State\StateStore;
use Watcher\State\UnreadableCounter;

/** State store construction: file, Redis (phpredis or Predis) and PDO, with the lazy connect wrapper. Used by {@see \Watcher\Config\Factory}, which keeps the public entry points. */
final class StateFactory
{
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
}
