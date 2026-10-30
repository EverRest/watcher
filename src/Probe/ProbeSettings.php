<?php

declare(strict_types=1);

namespace Watcher\Probe;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Config\InvalidConfig;

/**
 * The validated `probes` section. Validated even when `enabled` is false, so a later flip never reveals a bad value.
 * Errors name the key path only: a host, a secret name or an owner is never echoed back.
 */
final class ProbeSettings
{
    private const KEYS = ['enabled', 'warn_days', 'fail_days', 'hosts', 'secrets'];
    private const SECRET_KEYS = ['name', 'expires', 'owner'];
    private const HOST = '/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/D';
    private const NAME = '/^[A-Za-z0-9_.-]{1,128}$/D';

    /**
     * @param list<string> $hosts
     * @param list<array{name: string, expires: string, owner: string}> $secrets
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly int $warnDays,
        public readonly int $failDays,
        public readonly array $hosts,
        public readonly array $secrets,
    ) {
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        foreach (array_keys($raw) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                throw new InvalidConfig('Unknown configuration key: probes.' . $key);
            }
        }
        $enabled = $raw['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new InvalidConfig('probes.enabled must be true or false');
        }
        $warn = self::days($raw['warn_days'] ?? 30, 'probes.warn_days', 1);
        $fail = self::days($raw['fail_days'] ?? 7, 'probes.fail_days', 0);
        if ($fail >= $warn) {
            throw new InvalidConfig('probes.fail_days must be below probes.warn_days');
        }

        return new self($enabled, $warn, $fail, self::hosts($raw['hosts'] ?? []), self::secrets($raw['secrets'] ?? []));
    }

    private static function days(mixed $v, string $path, int $min): int
    {
        if (!is_int($v) || $v < $min || $v > 3650) {
            throw new InvalidConfig($path . ' must be an integer from ' . $min . ' to 3650');
        }

        return $v;
    }

    /** @return list<string> */
    private static function hosts(mixed $v): array
    {
        if (!is_array($v) || !array_is_list($v)) {
            throw new InvalidConfig('probes.hosts must be a list of host names');
        }
        foreach ($v as $i => $host) {
            if (!is_string($host) || preg_match(self::HOST, $host) !== 1) {
                throw new InvalidConfig("probes.hosts[$i] must be a host name");
            }
        }

        return $v;
    }

    /** @return list<array{name: string, expires: string, owner: string}> */
    private static function secrets(mixed $v): array
    {
        if (!is_array($v) || !array_is_list($v)) {
            throw new InvalidConfig('probes.secrets must be a list of maps with name, expires and owner');
        }
        $out = [];
        foreach ($v as $i => $entry) {
            $path = "probes.secrets[$i]";
            if (!is_array($entry)) {
                throw new InvalidConfig("$path must be a map with name, expires and owner");
            }
            foreach (array_keys($entry) as $key) {
                if (!in_array((string) $key, self::SECRET_KEYS, true)) {
                    throw new InvalidConfig("Unknown configuration key: $path.$key");
                }
            }
            if (!is_string($entry['name'] ?? null) || preg_match(self::NAME, $entry['name']) !== 1) {
                throw new InvalidConfig("$path.name must be letters, digits, dot, dash or underscore");
            }
            if (!is_string($entry['owner'] ?? null) || $entry['owner'] === '' || strlen($entry['owner']) > 128) {
                throw new InvalidConfig("$path.owner must be a non-empty string of at most 128 bytes");
            }
            $expires = $entry['expires'] ?? null;
            $date = is_string($expires) ? DateTimeImmutable::createFromFormat('!Y-m-d', $expires, new DateTimeZone('UTC')) : false;
            if (!is_string($expires) || $date === false || $date->format('Y-m-d') !== $expires) {
                throw new InvalidConfig("$path.expires must be a date as YYYY-MM-DD");
            }
            $out[] = ['name' => $entry['name'], 'expires' => $expires, 'owner' => $entry['owner']];
        }

        return $out;
    }
}
