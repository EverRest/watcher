<?php

declare(strict_types=1);

namespace Watcher\Source;

use RuntimeException;

/** Network, auth or limit failure reading the log backend. */
final class SourceUnavailable extends RuntimeException
{
    /** @param bool $tooLarge the reply (not the request) tripped the response cap: the caller may retry a smaller page */
    public function __construct(string $message = '', public readonly bool $tooLarge = false)
    {
        parent::__construct($message);
    }
}
