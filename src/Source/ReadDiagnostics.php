<?php

declare(strict_types=1);

namespace Watcher\Source;

/** Optional capability of a {@see LogSource}: counts of what its parser had to guess or drop. Counts only, never content. */
interface ReadDiagnostics
{
    /** @return array{unknown_levels: int, orphan_trace_lines: int, message_tails_dropped: int, truncated_lines?: int, regex_failures?: int, truncated_structured?: int, pages_shrunk?: int, malformed_entries?: int, oversized_lines?: int, k8s_read_failed?: int, k8s_gaps?: int, k8s_unattributed?: int, k8s_record_full?: int} counts for the last `lines()` call (the main read; earlier per-level passes re-parse the same lines and would count them twice) */
    public function readDiagnostics(): array;
}
