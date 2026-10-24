<?php

declare(strict_types=1);

namespace Watcher\Source;

/** Optional capability of a {@see LogSource}: counts of what its parser had to guess or drop. Counts only, never content. */
interface ReadDiagnostics
{
    /** @return array{unknown_levels: int, orphan_trace_lines: int, message_tails_dropped: int} totals over every `lines()` call so far */
    public function readDiagnostics(): array;
}
