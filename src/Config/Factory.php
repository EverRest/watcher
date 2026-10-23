<?php

declare(strict_types=1);

namespace Watcher\Config;

use GuzzleHttp\Client;
use PDO;
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
use Watcher\State\StateStore;

/** Builds the real collaborators from a validated config. The pdo table is not created here (see PdoStateStore::createTableSql). */
final class Factory
{
    public static function http(): Client
    {
        return new Client(['timeout' => 30, 'connect_timeout' => 10]);
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
            'redis' => new RedisStateStore(self::redis(is_array($st['redis'] ?? null) ? $st['redis'] : []), $clock),
            // The table is never created here: run PdoStateStore::createTableSql($driver, $table) once, in a migration.
            'pdo' => new PdoStateStore(
                new PDO((string) $st['pdo']['dsn'], $st['pdo']['username'] ?? null, $st['pdo']['password'] ?? null),
                $clock,
                (string) ($st['pdo']['table'] ?? 'watcher_state'),
            ),
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
            throw new InvalidConfig('state.redis: cannot connect or authenticate'); // never echo the driver message (may carry the password)
        }

        return $redis;
    }

    public static function analyzer(WatchConfig $c, Client $http): Analyzer
    {
        if ($c->analysis['provider'] !== 'anthropic') {
            throw self::unavailable('analysis.provider', (string) $c->analysis['provider']);
        }

        return new AnthropicAnalyzer((string) $c->analysis['api_key'], $http);
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
