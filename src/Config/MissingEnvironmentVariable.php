<?php

declare(strict_types=1);

namespace Watcher\Config;

/**
 * A required environment variable is not set. It is an {@see InvalidConfig}, so every handler that exits with code 2 on a bad
 * config keeps working; the message names the variable and the config key, never a value.
 */
final class MissingEnvironmentVariable extends InvalidConfig
{
}
