<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use LogicException;

/** A write reached the read-only store: a bug, surfaced as `internal_error`, never a silent success (FR-010). The message holds no key or value. */
final class ReadOnlyViolation extends LogicException
{
    public static function of(string $method): self
    {
        return new self('read-only state store refused ' . $method);
    }
}
