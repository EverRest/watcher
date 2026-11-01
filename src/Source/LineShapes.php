<?php

declare(strict_types=1);

namespace Watcher\Source;

/**
 * The regular expressions that recognise the shapes of a log line: a bracketed text record, exception forms, level words,
 * continuation and crash-trace heads. LineParser decides with them; they hold no state.
 */
final class LineShapes
{
    public const TEXT = '/^\[(?<dt>[^\]]+)\] (?<ch>[\w.-]+)\.(?<lvl>[A-Z]+)(?: \[(?<app>[^\]]*)\])?: (?<msg>.*?)(?<ctx> \{.*\})?+(?<extra> \[.*\]|\{.*\})?+$/';

    public const OBJECT_FORM = '/\[object\] \((?<t>[A-Za-z_][\w\\\\]*)\(code: [^)]*\):(?:.* at (?<f>(?:[A-Za-z]:)?[\\\\\/][\w.\-\\\\\/]*\.[A-Za-z0-9]{1,5}):(?<l>\d+)\))?/';

    public const MESSAGE_FORM = '/^(?<t>[A-Za-z_][\w\\\\]*(?:Exception|Error)): .* in (?<f>(?:[A-Za-z]:)?[\\\\\/][\w.\-\\\\\/]*\.[A-Za-z0-9]{1,5}):(?<l>\d+)$/';

    public const TRACE_LINE = '/^#\d+ (?<f>(?:[A-Za-z]:)?[\\\\\/][\w.\-\\\\\/]*\.[A-Za-z0-9]{1,5})\((?<l>\d+)\): /m';

    /**
     * How an unstructured line announces its level (only at the start, optionally after an ISO-like timestamp):
     * a level word (`ERROR x`, `Fatal error: x`, `[error] x`, `<WARN>`), a syslog `<PRI>` or logfmt `level=`/`lvl=`.
     */
    public const PLAIN_LEVEL = '/^(?:\d{4}[-\/]\d\d[-\/]\d\d[T ]\d\d:\d\d:\d\d\S*\s+)?(?:PHP\s+)?[\[<(]?(?<w>trace|debug|info|notice|warn|warning|err|error|crit|critical|alert|emerg|emergency|fatal|panic)(?!\w)/i';

    public const LOGFMT_LEVEL = '/(?:^|\s)(?:level|lvl)="?(?<w>\w+)/i';

    public const SYSLOG_PRI = '/^<(?<n>\d{1,3})>/';

    /**
     * A line that can only be the tail of a record: indented (Node/Go/Rust frames), `#0 frame`, `at x`, `Caused by:`,
     * `Stack trace:`, `Traceback`, `...`, a closer, `Foo\BarException: x`, `module.Class: x`, Go `goroutine N [state]:`,
     * `pkg.func(0x..)`, `created by x`, `exit status N`, Rust `stack backtrace:`.
     */
    public const CONTINUATION = '/^(?:$|\s|#\d|at\s|Caused by:|Stack trace:|PHP\s+(?:Stack trace:|\d+\.)|Traceback|\.\.\.|[})\]]|[\w.\\$]*(?:Error|Exception)\b(?::|$)|[a-z_]\w*(?:\.\w+)*\.[A-Z]\w*: |goroutine \d+ \[|[\w.\/*()-]+\((?:0x[0-9a-f]+|\.\.\.)|created by |exit status \d|stack backtrace:)/';

    /**
     * A head that announces a crash: everything up to the next record is its trace, whatever shape the frames take.
     * Anchored to the START of the message (after an optional timestamp, `PHP ` and level prefix), so crash words
     * inside user-controlled text (`ERROR payment failed: panic: x`) never open trace mode.
     */
    public const TRACE_HEAD = '/^(?:\d{4}[-\/]\d\d[-\/]\d\d[T ]\d\d:\d\d:\d\d\S*\s+)?(?:PHP\s+)?(?:[\[<(]?(?i:trace|debug|info|notice|warn|warning|err|error|crit|critical|alert|emerg|emergency|fatal|panic)(?!\w)[\]>)]?[:\s-]*)?(?:Traceback|panic:|goroutine \d+|(?:PHP )?Fatal error|fatal error:|Exception in thread|thread \'[^\']*\' panicked|Stack trace:|Caused by:)/';

    /** Lines that carry nothing beyond a stack frame: dropped (and counted) when no record is open to hold them. */
    public const FRAME_ONLY = '/^(?:#\d+ |\s+at\s|\s+\/\S*:\d+|\s+\d+: |goroutine \d+ \[|[\w.\/*-]+(?:\([^)]*\))?\((?:0x[0-9a-f]+|\.\.\.))/';

    /** Tail of a Laravel record whose context JSON holds raw line breaks: `[stacktrace]` and the line that closes the string (`"} []`). */
    public const STRUCTURED_TAIL = '/^(?:\[stacktrace\]|"\s*[}\],])/';
}
