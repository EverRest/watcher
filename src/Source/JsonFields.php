<?php

declare(strict_types=1);

namespace Watcher\Source;

/** Finds the first present key of a decoded JSON record, and flattens the rest into dotted fields. Pure. */
final class JsonFields
{
    /**
     * First present key wins; accepts a literal dotted key or a nested path. Records the path as used.
     *
     * @param array<mixed>  $data
     * @param list<string>  $keys
     * @param list<string>  $used
     */
    public static function pick(array $data, array $keys, array &$used): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $used[] = $key;

                return $data[$key];
            }
            $node = $data;
            foreach (explode('.', $key) as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) {
                    continue 2;
                }
                $node = $node[$part];
            }
            $used[] = $key;

            return $node;
        }

        return null;
    }

    /**
     * @param array<mixed>  $data
     * @param list<string>  $skip dotted paths already consumed
     *
     * @return array<string, scalar|null>
     */
    public static function flatten(array $data, string $prefix, array $skip): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $path = $prefix . $k;
            if (in_array($path, $skip, true)) {
                continue;
            }
            if (is_array($v)) {
                $out += self::flatten($v, $path . '.', $skip);
            } elseif (is_scalar($v) || $v === null) {
                $out[$path] = $v;
            }
        }

        return $out;
    }
}
