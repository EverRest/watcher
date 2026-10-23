<?php

declare(strict_types=1);

namespace Watcher\Cli;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal PSR-3 logger for the CLI: `watcher [level] message` on a stream. Context is deliberately ignored (it
 * could carry anything) and line breaks are collapsed, so a message cannot forge a second log line.
 */
final class StderrLogger extends AbstractLogger
{
    /** @param resource $stream */
    public function __construct(private $stream)
    {
    }

    /** @param array<mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $message));
        fwrite($this->stream, 'watcher [' . (is_string($level) ? $level : 'log') . '] ' . $text . "\n");
    }
}
