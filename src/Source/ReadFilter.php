<?php

declare(strict_types=1);

namespace Watcher\Source;

use InvalidArgumentException;
use Watcher\Level;

final class ReadFilter
{
    /** Patterns preg could not evaluate (bad pattern, invalid UTF-8): those lines were read, not dropped. */
    public int $patternErrors = 0;

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
