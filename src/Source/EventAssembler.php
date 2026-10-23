<?php

declare(strict_types=1);

namespace Watcher\Source;

use Generator;

/**
 * Joins physical lines into records: a `[timestamp]` text line, a JSON-object line or a line with a level prefix
 * starts a record; a continuation-shaped line (see LineParser::continues) is appended with "\n". Lazy: holds one record at a time.
 */
final class EventAssembler
{
    private readonly LineParser $parser;

    public function __construct(?LineParser $parser = null)
    {
        $this->parser = $parser ?? new LineParser();
    }

    /**
     * @param iterable<string> $lines
     *
     * @return Generator<int, string>
     */
    public function assemble(iterable $lines): Generator
    {
        $open = null;
        foreach ($lines as $line) {
            if ($open === null && $this->parser->isOrphanTrace($line)) {
                continue;
            }
            if ($open === null || !$this->parser->continues($line, $open)) {
                if ($open !== null) {
                    yield $open;
                }
                $open = $line;
            } else {
                $open .= "\n" . $line;
            }
        }
        if ($open !== null) {
            yield $open;
        }
    }
}
