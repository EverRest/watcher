<?php

declare(strict_types=1);

namespace Watcher\Source;

use RuntimeException;

/** Network, auth or limit failure reading the log backend. */
final class SourceUnavailable extends RuntimeException
{
}
