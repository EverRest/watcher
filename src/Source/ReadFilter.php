<?php

declare(strict_types=1);

namespace Watcher\Source;

use InvalidArgumentException;
use Watcher\Level;

final class ReadFilter
{
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

    /** Client-side check of the watch patterns: a server filter is a coarse net and the caller decides what is wanted. */
    public function matchesWatch(string $message): bool
    {
        foreach ($this->watchPatterns as $re) {
            if (@preg_match("\x01" . $re . "\x01u", $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
