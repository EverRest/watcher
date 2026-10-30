<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;
use Watcher\Level;

/** Typed readers shared by the section validators: each reads one key of a raw section and throws InvalidConfig with its key path. */
final class SectionFields
{
    /**
     * Rejects keys outside $allowed, naming the key path and never a value (a typo must not silently disable a setting).
     *
     * @param array<array-key, mixed> $section
     * @param list<string> $allowed
     */
    public static function only(array $section, array $allowed, string $path): void
    {
        foreach (array_keys($section) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new InvalidConfig('Unknown configuration key: ' . $path . '.' . $key);
            }
        }
    }

    /**
     * @param array<string, mixed> $section
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    public static function known(array $section, array $allowed, string $path): array
    {
        self::only($section, $allowed, $path);

        return $section;
    }

    public static function regex(mixed $re, string $path): void
    {
        if (!is_string($re) || $re === '' || @preg_match($re, '') === false) {
            throw new InvalidConfig($path . ' must be a valid regular expression');
        }
    }

    /**
     * @param array<array-key, mixed> $from
     * @return array<string, mixed>
     */
    public static function section(array $from, string $key): array
    {
        $v = $from[$key] ?? [];
        if (!is_array($v)) {
            throw new InvalidConfig($key . ' must be a map');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $from */
    public static function str(array $from, string $key, string $path): string
    {
        $v = $from[$key] ?? null;
        if (!is_string($v) || $v === '') {
            throw new InvalidConfig($path . ' is required');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $from */
    public static function optStr(array $from, string $key, string $default, string $path): string
    {
        $v = $from[$key] ?? $default;
        if (!is_string($v) || $v === '') {
            throw new InvalidConfig($path . ' must be a non-empty string');
        }

        return $v;
    }

    /**
     * A strict boolean flag: true/false only, a string such as 'false' is rejected.
     *
     * @param array<array-key, mixed> $from
     */
    public static function flag(array $from, string $key, string $path, bool $default = true): bool
    {
        $v = $from[$key] ?? $default;
        if (!is_bool($v)) {
            throw new InvalidConfig($path . ' must be true or false');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $parent a section holding `$key => ['enabled' => bool]` */
    public static function subFlag(array $parent, string $key, string $path): bool
    {
        $sub = $parent[$key] ?? [];
        if (!is_array($sub)) {
            throw new InvalidConfig($path . ' must be a map');
        }
        self::only($sub, ['enabled'], $path);

        return self::flag($sub, 'enabled', $path . '.enabled');
    }

    /**
     * A list or map that reserves the key `enabled` for its flag (entries never are a bare boolean).
     *
     * @return array{0: mixed, 1: bool} the value without the flag, the flag
     */
    public static function reserved(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_key_exists('enabled', $value)) {
            return [$value, true];
        }
        $on = $value['enabled'];
        if (!is_bool($on)) {
            throw new InvalidConfig($path . '.enabled must be true or false');
        }
        unset($value['enabled']);

        return [$value, $on];
    }

    /** @param array<array-key, mixed> $from */
    public static function posInt(array $from, string $key, int $default, string $path): int
    {
        $v = $from[$key] ?? $default;
        if (!is_int($v) || $v < 1) {
            throw new InvalidConfig($path . ' must be a positive integer');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $from */
    public static function num(array $from, string $key, float $default, string $path, ?float $min = null): float
    {
        $v = $from[$key] ?? $default;
        if (!is_int($v) && !is_float($v)) {
            throw new InvalidConfig($path . ' must be a number');
        }
        if ($min !== null && $v < $min) {
            throw new InvalidConfig($path . ' must be at least ' . $min);
        }

        return (float) $v;
    }

    /** @param array<array-key, mixed> $from */
    public static function level(array $from, string $key, string $default, string $path): Level
    {
        $v = $from[$key] ?? $default;
        $level = is_string($v) ? Level::parse($v) : null;
        // Level::parse maps unknown names to Info; a typo in config must not pass silently.
        if ($level === null || ($level === Level::Info && strtolower(trim((string) $v)) !== 'info')) {
            throw new InvalidConfig($path . ' must be a level name');
        }

        return $level;
    }

    /** @param array<array-key, mixed> $from */
    public static function boundedInt(array $from, string $key, int $default, int $min, int $max): int
    {
        $v = $from[$key] ?? $default;
        if (!is_int($v) || $v < $min || $v > $max) {
            throw new InvalidConfig("api_context.$key must be an integer between $min and $max");
        }

        return $v;
    }

    /** ISO-8601 duration -> seconds (a month counts 30 days, a year 365); null when it is not one. */
    public static function seconds(string $iso): ?int
    {
        try {
            $i = new \DateInterval($iso);
        } catch (\Exception) {
            return null;
        }

        return ((($i->y * 365 + $i->m * 30 + $i->d) * 24 + $i->h) * 60 + $i->i) * 60 + $i->s;
    }

    /** @param array<array-key, mixed> $config */
    public static function absent(array $config, string $key): null
    {
        if (($config[$key] ?? null) !== null) {
            throw new InvalidConfig($key . ' must be a map');
        }

        return null;
    }

    public static function isDate(mixed $v): bool
    {
        $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;

        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /** @param array<array-key, mixed> $s */
    public static function maxResponseMb(array $s): int
    {
        $v = self::posInt($s, 'max_response_mb', 16, 'source.max_response_mb');
        if ($v > 64) {
            throw new InvalidConfig('source.max_response_mb must be between 1 and 64');
        }

        return $v;
    }
}
