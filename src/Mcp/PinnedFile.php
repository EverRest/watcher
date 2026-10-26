<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/**
 * A file opened once and verified against its {@see ApprovedPath}; the handle stays open so the inode cannot be recycled meanwhile.
 * The identity is taken from the handle at read time (inode, size, mtime, ctime): a same-inode rewrite changes mtime and ctime.
 */
final class PinnedFile
{
    /** @param resource $handle */
    public function __construct(private $handle, public readonly ApprovedPath $approved, public readonly string $bytes, private readonly int $mtime, private readonly int $ctime)
    {
    }

    /**
     * Whether the path still names the inode that was read, unchanged in size, mtime and ctime (called right before the file is
     * included). Decision: the include re-opens the path, so a window remains between this check and the include; it needs a writer
     * who owns the file or its directory, which the policy already refuses (foreign or group/world-writable) — a same-user attacker can run code anyway.
     */
    public function stillTheSame(): bool
    {
        clearstatcache(true); // PHP caches stat results per path; a check must read the file system
        $st = @lstat($this->approved->real);

        return $st !== false && $st['dev'] === $this->approved->dev && $st['ino'] === $this->approved->ino && ($st['mode'] & 0170000) === 0100000
            && $st['size'] === strlen($this->bytes) && $st['mtime'] === $this->mtime && $st['ctime'] === $this->ctime;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
