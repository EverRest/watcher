<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use RuntimeException;

/** JSON-RPC -32601. */
final class MethodNotFound extends RuntimeException
{
}
