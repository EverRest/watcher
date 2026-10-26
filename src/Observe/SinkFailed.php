<?php

declare(strict_types=1);

namespace Watcher\Observe;

use RuntimeException;

/** A sink could not write. The message is a short fixed code (e.g. `http status 500`), never the cause's text. */
final class SinkFailed extends RuntimeException
{
}
