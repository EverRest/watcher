<?php

declare(strict_types=1);

namespace Watcher\Schedule;

use RuntimeException;

/** The run lock was found expired or held by another runner: this run must stop, it no longer owns the window. */
final class LockLost extends RuntimeException
{
}
