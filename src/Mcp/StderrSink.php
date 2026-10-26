<?php

declare(strict_types=1);

namespace Watcher\Mcp;

use Psr\Log\AbstractLogger;
use Stringable;

/** Logger for the state drivers: stderr only, class names and masked messages (FR-002). Context is never printed. */
final class StderrSink extends AbstractLogger
{
    /** @param resource $err */
    public function __construct(private $err)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        fwrite($this->err, 'watcher [' . (is_string($level) ? $level : 'log') . '] ' . preg_replace('/[^\x20-\x7E]/', '?', mb_substr((string) $message, 0, 160)) . "\n");
    }
}
