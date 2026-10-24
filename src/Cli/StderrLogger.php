<?php

declare(strict_types=1);

namespace Watcher\Cli;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal PSR-3 logger for the CLI: `watcher [level] message` on a stream. Context is deliberately ignored (it
 * could carry anything) and line breaks and control characters (ESC included) are collapsed, so a message cannot forge a second log line or drive the terminal.
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
        // Invalid UTF-8 is scrubbed (never blanks the message); ESC and the other C0 controls (terminal escapes from a hostile reason phrase) become spaces.
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', mb_scrub((string) $message, 'UTF-8')));
        fwrite($this->stream, 'watcher [' . (is_string($level) ? $level : 'log') . '] ' . $text . "\n");
    }
}
