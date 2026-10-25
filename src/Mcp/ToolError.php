<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use RuntimeException;

/** A refusal with a code of the closed set in {@see Envelope::MESSAGES}; the message is the fixed sentence, never a value. */
final class ToolError extends RuntimeException
{
    /** @param array<string, mixed> $extra typed fields shown beside the error (e.g. spend_today) */
    public function __construct(public readonly string $errorCode, public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }
}
