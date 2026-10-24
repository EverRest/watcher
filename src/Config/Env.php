<?php

declare(strict_types=1);

namespace Watcher\Config;

/**
 * Environment variables for plain-PHP configs. Unset or empty = the default (a Kubernetes `value: ""` is "not set").
 * An invalid value throws InvalidConfig naming the variable, never its value (it may be a secret).
 */
final class Env
{
    public static function bool(string $name, bool $default): bool
    {
        $raw = self::raw($name);
        if ($raw === null) {
            return $default;
        }

        return match (strtolower(trim($raw))) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new InvalidConfig("environment variable $name must be true or false"),
        };
    }

    public static function int(string $name, int $default): int
    {
        $raw = self::raw($name);
        if ($raw === null) {
            return $default;
        }
        if (preg_match('/^\s*-?\d+\s*$/', $raw) !== 1) {
            throw new InvalidConfig("environment variable $name must be an integer");
        }

        return (int) $raw;
    }

    public static function float(string $name, float $default): float
    {
        $raw = self::raw($name);
        if ($raw === null) {
            return $default;
        }
        if (!is_numeric(trim($raw))) {
            throw new InvalidConfig("environment variable $name must be a number");
        }

        return (float) $raw;
    }

    public static function string(string $name, ?string $default = null): ?string
    {
        return self::raw($name) ?? $default;
    }

    /**
     * @param list<string> $default
     * @return list<string> comma separated, trimmed, empty items dropped
     */
    public static function list(string $name, array $default = []): array
    {
        $raw = self::raw($name);

        return $raw === null ? $default : array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }

    private static function raw(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false) {
            $v = $_ENV[$name] ?? $_SERVER[$name] ?? false;
        }

        return is_string($v) && $v !== '' ? $v : null;
    }
}
