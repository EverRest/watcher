<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/**
 * The few JSON Schema keywords the tool inputs use (type, enum, pattern, min/max, required, properties,
 * additionalProperties, items, uniqueItems, maxItems, maxProperties, propertyNames). No dependency (FR-005).
 * Returns the path of the first violation, never a value.
 */
final class SchemaCheck
{
    /** @param array<string, mixed> $schema */
    public static function violation(array $schema, mixed $value, string $path = ''): ?string
    {
        if (isset($schema['type']) && !self::typeOk($schema['type'], $value)) {
            return $path;
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return $path;
        }
        if (is_string($value)) {
            if (isset($schema['pattern']) && is_string($schema['pattern']) && preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/Du', $value) !== 1) {
                return $path;
            }
            $len = mb_strlen($value);
            if ((isset($schema['minLength']) && $len < $schema['minLength']) || (isset($schema['maxLength']) && $len > $schema['maxLength'])) {
                return $path;
            }
        }
        if ((is_int($value) || is_float($value)) && ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum']))) {
            return $path;
        }
        if (is_array($value)) {
            return array_is_list($value) ? self::list($schema, $value, $path) : self::object($schema, $value, $path);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed> $value
     */
    private static function list(array $schema, array $value, string $path): ?string
    {
        if ($value === [] && ($schema['type'] ?? null) === 'object') {
            return self::object($schema, [], $path);
        }
        if ((isset($schema['maxItems']) && count($value) > $schema['maxItems'])
            || (($schema['uniqueItems'] ?? false) === true && count(array_unique(array_map(static fn (mixed $v): string => serialize($v), $value))) !== count($value))) {
            return $path;
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $i => $item) {
                if (($v = self::violation($schema['items'], $item, $path . '[' . $i . ']')) !== null) {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed> $value
     */
    private static function object(array $schema, array $value, string $path): ?string
    {
        $props = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $req) {
            if (is_string($req) && !array_key_exists($req, $value)) {
                return self::join($path, $req);
            }
        }
        if (isset($schema['maxProperties']) && count($value) > $schema['maxProperties']) {
            return $path;
        }
        foreach ($value as $name => $item) {
            $name = (string) $name;
            $sub = self::join($path, $name);
            if (isset($schema['propertyNames']['pattern']) && preg_match('/' . str_replace('/', '\/', (string) $schema['propertyNames']['pattern']) . '/Du', $name) !== 1) {
                return $sub;
            }
            if (isset($props[$name]) && is_array($props[$name])) {
                if (($v = self::violation($props[$name], $item, $sub)) !== null) {
                    return $v;
                }
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                return $sub;
            } elseif (is_array($schema['additionalProperties'] ?? null) && ($v = self::violation($schema['additionalProperties'], $item, $sub)) !== null) {
                return $v;
            }
        }

        return null;
    }

    /** A client-chosen name is shown only when it is a plain identifier; otherwise `?` (never a secret echoed as a "property"). */
    private static function join(string $path, string $name): string
    {
        $name = preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,30}$/D', $name) === 1 ? $name : '?';

        return $path === '' ? $name : $path . '.' . $name;
    }

    private static function typeOk(mixed $type, mixed $value): bool
    {
        foreach (is_array($type) ? $type : [$type] as $t) {
            $ok = match ($t) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
