<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;
use Watcher\State\Redis\RedisClient;

/** Validates the `state` section: file, Redis and PDO stores. */
final class StateSection
{
    /**
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    public static function state(array $s): array
    {
        SectionFields::only($s, ['driver', 'retention', 'redis', 'pdo', 'file'], 'state');
        $driver = SectionFields::str($s, 'driver', 'state.driver');
        match ($driver) {
            'redis' => self::redis(SectionFields::section($s, 'redis')),
            'pdo' => self::pdo(SectionFields::section($s, 'pdo')),
            'file' => SectionFields::str(SectionFields::known(SectionFields::section($s, 'file'), ['path'], 'state.file'), 'path', 'state.file.path'),
            default => throw new InvalidConfig('state.driver must be redis, pdo or file'),
        };

        return array_merge($s, ['retention' => SectionFields::posInt($s, 'retention', 2592000, 'state.retention')]);
    }

    /** @param array<string, mixed> $r an injected `instance` wins; otherwise host/port/password/database build one */
    public static function redis(array $r): void
    {
        SectionFields::only($r, ['instance', 'host', 'port', 'username', 'password', 'database', 'client', 'compress_min_bytes', 'read_timeout', 'prefix'], 'state.redis');
        if (array_key_exists('prefix', $r) && (!is_string($r['prefix']) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $r['prefix']) !== 1)) {
            throw new InvalidConfig('state.redis.prefix must be 1 to 64 characters of letters, digits and _ . : -');
        }
        if (!in_array($r['client'] ?? 'auto', ['auto', 'phpredis', 'predis'], true)) {
            throw new InvalidConfig('state.redis.client must be auto, phpredis or predis');
        }
        $min = $r['compress_min_bytes'] ?? 512;
        if (!is_int($min) || $min < 0) {
            throw new InvalidConfig('state.redis.compress_min_bytes must be an integer of 0 or more (0 = never compress)');
        }
        if (isset($r['read_timeout']) && (!(is_int($r['read_timeout']) || is_float($r['read_timeout'])) || $r['read_timeout'] <= 0)) {
            throw new InvalidConfig('state.redis.read_timeout must be a positive number of seconds');
        }
        if (array_key_exists('instance', $r)) {
            $i = $r['instance'];
            if (!($i instanceof \Redis || $i instanceof RedisClient || is_a($i, 'Predis\\ClientInterface'))) {
                throw new InvalidConfig('state.redis.instance must be a \\Redis, a Predis client or a ' . RedisClient::class);
            }

            return;
        }
        SectionFields::optStr($r, 'host', '127.0.0.1', 'state.redis.host');
        $port = $r['port'] ?? 6379;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidConfig('state.redis.port must be an integer from 1 to 65535');
        }
        $db = $r['database'] ?? 0;
        if (!is_int($db) || $db < 0) {
            throw new InvalidConfig('state.redis.database must be a non-negative integer');
        }
        if (isset($r['password']) && !is_string($r['password'])) {
            throw new InvalidConfig('state.redis.password must be a string');
        }
        if (isset($r['username']) && (!is_string($r['username']) || $r['username'] === '')) {
            throw new InvalidConfig('state.redis.username must be a non-empty string');
        }
    }

    /** @param array<string, mixed> $p */
    public static function pdo(array $p): void
    {
        SectionFields::only($p, ['dsn', 'username', 'password', 'table'], 'state.pdo');
        SectionFields::str($p, 'dsn', 'state.pdo.dsn');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', SectionFields::optStr($p, 'table', 'watcher_state', 'state.pdo.table')) !== 1) {
            throw new InvalidConfig('state.pdo.table must be a plain identifier');
        }
        foreach (['username', 'password'] as $k) {
            if (isset($p[$k]) && !is_string($p[$k])) {
                throw new InvalidConfig('state.pdo.' . $k . ' must be a string');
            }
        }
    }
}
