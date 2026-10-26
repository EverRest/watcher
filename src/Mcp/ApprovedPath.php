<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/** A path that passed {@see PathPolicy}, with the identity (device, inode) the check saw: the reader must open the same file. */
final class ApprovedPath
{
    public function __construct(public readonly string $real, public readonly int $dev, public readonly int $ino)
    {
    }
}
