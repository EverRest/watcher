<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use RuntimeException;

/** The state store failed; see {@see GuardedState}. Carries the failing class name and nothing else. */
final class StateFailure extends RuntimeException
{
    public function __construct(public readonly string $errorClass)
    {
        parent::__construct('state unavailable (' . $errorClass . ')');
    }
}
