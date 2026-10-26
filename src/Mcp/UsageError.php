<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use RuntimeException;

/** A usage problem found while starting (exit 2); the message names the setting, never a value. */
final class UsageError extends RuntimeException
{
}
