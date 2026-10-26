<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Watcher\Observe\FilePolicy;

/**
 * FR-067: the only way a client-chosen path reaches the filesystem. Accepted only when: no NUL, 1..1024 characters, no
 * backslash, the path itself is not a symlink, `realpath` succeeds, the result is a regular `.php` file with one link, owned by
 * the process user and not group/world writable ({@see FilePolicy}, shared with `observe.file.path`), in a directory that is
 * not a symlink, owned by us or root and not group/world writable (a sticky bit excuses it), and under an allowed root (roots
 * are compared after `realpath`, with a separator, so `/srv/app` does not admit `/srv/app-evil`). A refusal is
 * `path_not_allowed` before any read and never echoes the path.
 */
final class PathPolicy
{
    /** @var list<string> */
    private array $roots = [];

    /** @param list<string> $roots */
    public function __construct(array $roots)
    {
        foreach ($roots as $r) {
            $real = @realpath($r);
            if ($real !== false && is_dir($real)) {
                $this->roots[] = rtrim($real, '/');
            }
        }
    }

    /** @throws ToolError path_not_allowed */
    public function approve(string $path): ApprovedPath
    {
        if ($path === '' || strlen($path) > 1024 || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw self::refused();
        }
        clearstatcache(true); // PHP caches lstat() and realpath(); a mode or owner check must read the file system, not the cache
        $own = @lstat($path);
        if ($own === false || FilePolicy::isLink($own)) {
            throw self::refused(); // a symlink is refused whatever it points to, a chain included
        }
        $real = @realpath($path);
        if ($real === false || !str_ends_with($real, '.php') || !$this->inRoot($real)) {
            throw self::refused();
        }
        $st = @lstat($real);
        if ($st === false || !FilePolicy::privateFile($st) || $st['nlink'] !== 1) {
            throw self::refused(); // directory, device, FIFO, group/world-writable, foreign owner, hard-linked
        }
        $parent = @lstat(dirname($real));
        if ($parent === false || FilePolicy::isLink($parent) || ($parent['mode'] & 0170000) !== 0040000
            || (!FilePolicy::ownedByUs($parent['uid']) && $parent['uid'] !== 0)
            || (($parent['mode'] & 0022) !== 0 && ($parent['mode'] & 01000) === 0)) {
            throw self::refused();
        }

        return new ApprovedPath($real, $st['dev'], $st['ino']);
    }

    private function inRoot(string $real): bool
    {
        foreach ($this->roots as $root) {
            if (str_starts_with($real, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    public static function refused(): ToolError
    {
        return new ToolError('path_not_allowed');
    }
}
