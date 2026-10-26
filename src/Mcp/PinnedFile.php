<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/** A file opened once and verified against its {@see ApprovedPath}; the handle stays open so the inode cannot be recycled meanwhile. */
final class PinnedFile
{
    /** @param resource $handle */
    public function __construct(private $handle, public readonly ApprovedPath $approved, public readonly string $bytes)
    {
    }

    /** Whether the path still names the inode that was read (called right before the file is included). */
    public function stillTheSame(): bool
    {
        $st = @lstat($this->approved->real);

        return $st !== false && $st['dev'] === $this->approved->dev && $st['ino'] === $this->approved->ino && ($st['mode'] & 0170000) === 0100000;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
