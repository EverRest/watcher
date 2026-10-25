<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Watcher\Observe\FilePolicy;

/**
 * Bounded read of an approved file (FR-067): open once without blocking, `fstat` the handle and compare it with what the
 * policy saw (device, inode, regular, one link, ours, not group/world writable), then read at most 256 KB + 1. A file swapped
 * for a symlink target, a FIFO or another inode between the check and the open is `path_not_allowed`, never read.
 */
final class ConfigFileReader
{
    public const MAX_BYTES = 262144;

    /** @throws ToolError path_not_allowed, file_unreadable, file_too_large */
    public function read(ApprovedPath $approved): PinnedFile
    {
        $pre = @lstat($approved->real);
        if ($pre === false || $pre['dev'] !== $approved->dev || $pre['ino'] !== $approved->ino || !FilePolicy::isRegular($pre)) {
            throw PathPolicy::refused(); // swapped before the open: a FIFO would block in fopen
        }
        $h = @fopen($approved->real, 'rn'); // n: O_NONBLOCK, so a FIFO swapped in after the lstat cannot hang the server
        if ($h === false) {
            throw new ToolError('file_unreadable');
        }
        $st = fstat($h);
        if ($st === false || $st['dev'] !== $approved->dev || $st['ino'] !== $approved->ino || !FilePolicy::privateFile($st) || $st['nlink'] !== 1) {
            fclose($h);
            throw PathPolicy::refused();
        }
        if ($st['size'] > self::MAX_BYTES) {
            fclose($h);
            throw new ToolError('file_too_large');
        }
        $bytes = stream_get_contents($h, self::MAX_BYTES + 1);
        if ($bytes === false) {
            fclose($h);
            throw new ToolError('file_unreadable');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            fclose($h);
            throw new ToolError('file_too_large');
        }

        return new PinnedFile($h, $approved, $bytes);
    }
}
