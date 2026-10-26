<?php

declare(strict_types=1);

namespace Watcher\Observe;

/**
 * The ownership rule for files and directories the package trusts, as a pure check on an `lstat()` array: ours, not a symlink,
 * and not group/world writable (a directory may be if it carries the sticky bit). One copy, used by `observe.file.path`
 * ({@see FileSink}) and by the MCP path policy.
 */
final class FilePolicy
{
    public static function ownedByUs(int $uid): bool
    {
        return !function_exists('posix_geteuid') || $uid === posix_geteuid();
    }

    /** @param array<int|string, int> $st */
    public static function isLink(array $st): bool
    {
        return ($st['mode'] & 0170000) === 0120000;
    }

    /** @param array<int|string, int> $st */
    public static function isRegular(array $st): bool
    {
        return ($st['mode'] & 0170000) === 0100000;
    }

    /**
     * The state directory rule: ours, not a link, and not group/world writable unless it has the sticky bit.
     *
     * @param array<int|string, int> $st
     */
    public static function privateDirectory(array $st): bool
    {
        return !self::isLink($st) && self::ownedByUs($st['uid']) && (($st['mode'] & 0022) === 0 || ($st['mode'] & 01000) !== 0);
    }

    /**
     * A regular file we own that nobody else can write.
     *
     * @param array<int|string, int> $st
     */
    public static function privateFile(array $st): bool
    {
        return self::isRegular($st) && self::ownedByUs($st['uid']) && ($st['mode'] & 0022) === 0;
    }
}
