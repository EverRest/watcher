<?php

declare(strict_types=1);

namespace Watcher\Reduce;

use RuntimeException;

/** Text could not be normalised (invalid UTF-8, regex failure). Callers that redact must fail closed. */
final class NormalizationException extends RuntimeException
{
}
