<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use RuntimeException;

/** JSON-RPC -32602. The message names a property (sanitised), never a value. */
final class InvalidParams extends RuntimeException
{
}
