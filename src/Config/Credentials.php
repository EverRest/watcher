<?php

declare(strict_types=1);

namespace Watcher\Config;

use Watcher\Source\Kubernetes\SecretValue;

/** The credential holders of a validated config: hide() wraps the secret leaves, reveal() and plain() read them back. */
final class Credentials
{
    /**
     * The credential leaves of a section become {@see SecretValue} holders, so a dump of the config (var_export, print_r, serialize)
     * shows none. Paths are dotted keys inside the section; a missing or non-string leaf is left alone.
     *
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    public static function hide(array $section, string ...$paths): array
    {
        foreach ($paths as $path) {
            $parts = explode('.', $path, 2);
            $head = $parts[0];
            if (!array_key_exists($head, $section)) {
                continue;
            }
            if (isset($parts[1]) && is_array($section[$head])) {
                $section[$head] = self::hide($section[$head], $parts[1]);
            } elseif (!isset($parts[1]) && is_string($section[$head])) {
                $section[$head] = new SecretValue($section[$head]);
            }
        }

        return $section;
    }

    /** The value of a holder made by {@see hide()}, read at the point of use; a plain string passes through. */
    public static function reveal(mixed $value): string
    {
        return $value instanceof SecretValue ? $value->reveal() : (string) $value;
    }

    /** A section with every holder read back, for comparing two sections: a SecretValue alone compares equal to any other. Never for output. */
    public static function plain(mixed $value): mixed
    {
        if ($value instanceof SecretValue) {
            return $value->reveal();
        }

        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }
}
