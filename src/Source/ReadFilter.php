<?php

declare(strict_types=1);

namespace Watcher\Source;

use InvalidArgumentException;
use Watcher\Level;

final class ReadFilter
{
    /** Patterns preg could not evaluate (bad pattern, invalid UTF-8): those lines were read, not dropped. */
    public int $patternErrors = 0;

    /** A physical line above this size never reaches the parser (A4-01): it is dropped and counted. */
    public const MAX_LINE_BYTES = 65536;

    /** Lines dropped because they were larger than MAX_LINE_BYTES (a count, never the text). */
    public int $oversizedLines = 0;

    /** Why the read stopped early: cap, pages, time, cursor, deadline or memory. Null = not truncated. Set by the source or the stop hook. */
    public ?string $truncationReason = null;

    /** @var (\Closure(): ?string)|null asked between pages and per event: a reason means stop reading now */
    private ?\Closure $stopCheck = null;

    /**
     * @param list<string> $watchPatterns RE2-compatible regexes without delimiters, `(?i)` prefix when case-insensitive
     */
    public function __construct(
        public readonly Level $minLevel,
        public readonly array $watchPatterns,
        public readonly int $eventCap,
    ) {
        if ($eventCap < 1) {
            throw new InvalidArgumentException('eventCap must be at least 1.');
        }
    }

    /** @param \Closure(): ?string $check returns a stop reason (deadline, memory) or null to go on */
    public function stopWhen(\Closure $check): void
    {
        $this->stopCheck = $check;
    }

    /** True (and the reason is recorded) when the run's deadline or memory guard says stop; sources call it between pages and per event. */
    public function shouldStop(): bool
    {
        $reason = $this->stopCheck === null ? null : ($this->stopCheck)();
        if ($reason === null) {
            return false;
        }
        $this->truncationReason ??= $reason;

        return true;
    }

    /**
     * True when the line is too large to parse; counts it. A structured (`{`) line is never dropped here: the parser turns
     * one over the cap into a placeholder event that keeps its level (R-4).
     */
    public function oversized(string $line): bool
    {
        if (strlen($line) <= self::MAX_LINE_BYTES || str_starts_with(ltrim(substr($line, 0, 64)), '{')) {
            return false;
        }
        ++$this->oversizedLines;

        return true;
    }

    /**
     * Client-side check of the watch patterns: a server filter is a coarse net and the caller decides what is wanted.
     * The backend searched the whole raw line, so a pushed `error_type` pattern must also be tried on the parsed
     * exception class, which is not part of the message (W1).
     */
    public function matchesWatch(string $message, ?string $errorType = null): bool
    {
        foreach ($this->watchPatterns as $re) {
            foreach ($errorType === null ? [$message] : [$message, $errorType] as $subject) {
                $result = @preg_match("\x01" . $re . "\x01u", $subject);
                if ($result === false) {
                    ++$this->patternErrors; // fail open: the line is only a candidate, a dropped one is lost for good

                    return true;
                }
                if ($result === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
