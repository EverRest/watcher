<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Watcher\Level;

/** Parses one log line into a LogEvent. Text vs JSON is decided per line, so mixed streams work. */
final class LineParser
{
    private const TEXT = '/^\[(?<dt>[^\]]+)\] (?<ch>[\w.-]+)\.(?<lvl>[A-Z]+)(?: \[(?<app>[^\]]*)\])?: (?<msg>.*?)(?<ctx> \{.*\})?(?<extra> \[.*\]|\{.*\})?$/';

    private const OBJECT_FORM = '/\[object\] \((?<t>[A-Za-z_][\w\\\\]*)\(code: [^)]*\):(?:.* at (?<f>(?:[A-Za-z]:)?[\\\\\/][\w.\-\\\\\/]*\.[A-Za-z0-9]{1,5}):(?<l>\d+)\))?/';
    private const MESSAGE_FORM = '/^(?<t>[A-Za-z_][\w\\\\]*(?:Exception|Error)): .* in (?<f>(?:[A-Za-z]:)?[\\\\\/][\w.\-\\\\\/]*\.[A-Za-z0-9]{1,5}):(?<l>\d+)$/';
    private const TRACE_LINE = '/^#\d+ (?<f>(?:[A-Za-z]:)?[\\\\\/][\w.\-\\\\\/]*\.[A-Za-z0-9]{1,5})\((?<l>\d+)\): /m';

    /** An exception type is a PHP class name (max 120 chars); anything else is free text and never leaves. */
    public const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D';
    public const CLASS_NAME_MAX = 120;

    /**
     * How an unstructured line announces its level (only at the start, optionally after an ISO-like timestamp):
     * a level word (`ERROR x`, `Fatal error: x`, `[error] x`, `<WARN>`), a syslog `<PRI>` or logfmt `level=`/`lvl=`.
     */
    private const PLAIN_LEVEL = '/^(?:\d{4}[-\/]\d\d[-\/]\d\d[T ]\d\d:\d\d:\d\d\S*\s+)?(?:PHP\s+)?[\[<(]?(?<w>trace|debug|info|notice|warn|warning|err|error|crit|critical|alert|emerg|emergency|fatal|panic)(?!\w)/i';
    private const LOGFMT_LEVEL = '/(?:^|\s)(?:level|lvl)="?(?<w>\w+)/i';
    private const SYSLOG_PRI = '/^<(?<n>\d{1,3})>/';
    /**
     * A line that can only be the tail of a record: indented (Node/Go/Rust frames), `#0 frame`, `at x`, `Caused by:`,
     * `Stack trace:`, `Traceback`, `...`, a closer, `Foo\BarException: x`, `module.Class: x`, Go `goroutine N [state]:`,
     * `pkg.func(0x..)`, `created by x`, `exit status N`, Rust `stack backtrace:`.
     */
    private const CONTINUATION = '/^(?:$|\s|#\d|at\s|Caused by:|Stack trace:|PHP\s+(?:Stack trace:|\d+\.)|Traceback|\.\.\.|[})\]]|[\w.\\$]*(?:Error|Exception)\b(?::|$)|[a-z_]\w*(?:\.\w+)*\.[A-Z]\w*: |goroutine \d+ \[|[\w.\/*()-]+\((?:0x[0-9a-f]+|\.\.\.)|created by |exit status \d|stack backtrace:)/';
    /**
     * A head that announces a crash: everything up to the next record is its trace, whatever shape the frames take.
     * Anchored to the START of the message (after an optional timestamp, `PHP ` and level prefix), so crash words
     * inside user-controlled text (`ERROR payment failed: panic: x`) never open trace mode.
     */
    private const TRACE_HEAD = '/^(?:\d{4}[-\/]\d\d[-\/]\d\d[T ]\d\d:\d\d:\d\d\S*\s+)?(?:PHP\s+)?(?:[\[<(]?(?i:trace|debug|info|notice|warn|warning|err|error|crit|critical|alert|emerg|emergency|fatal|panic)(?!\w)[\]>)]?[:\s-]*)?(?:Traceback|panic:|goroutine \d+|(?:PHP )?Fatal error|fatal error:|Exception in thread|thread \'[^\']*\' panicked|Stack trace:|Caused by:)/';
    /** Lines that carry nothing beyond a stack frame: dropped (and counted) when no record is open to hold them. */
    private const FRAME_ONLY = '/^(?:#\d+ |\s+at\s|\s+\/\S*:\d+|\s+\d+: |goroutine \d+ \[|[\w.\/*-]+(?:\([^)]*\))?\((?:0x[0-9a-f]+|\.\.\.))/';
    /** Lines a trace may absorb beyond its head when they match no known frame shape. */
    private const TRACE_ABSORB_CAP = 200;

    private const LEVEL_KEYS = ['level', 'severity', 'status'];
    private const MESSAGE_KEYS = ['message', 'msg'];
    private const TYPE_KEYS = ['exception.class', 'error.kind'];
    private const TRACE_KEYS = ['exception.trace', 'error.stack'];
    private const TIME_KEYS = ['time', 'timestamp', '@timestamp', 'datetime', 'ts'];

    /** Incremented per event whose level token was present but unrecognised (it is then Info). */
    public int $unknownLevels = 0;

    /** Trace-shaped lines dropped because no record was open to carry them (a page or stream that starts mid-trace). */
    public int $orphanTraceLines = 0;

    /** Unrecognised lines refused by the per-record absorb cap (the next one starts a new record). */
    public int $traceLinesCapped = 0;

    /**
     * Is `$line`, arriving with no open record, a bare stack frame? Then it is dropped (and counted), never an event.
     * Exception headers (`FooException: msg`) and lines of a stream with a level label (`$labelled`) carry information
     * and are never dropped.
     */
    public function isOrphanTrace(string $line, bool $labelled = false): bool
    {
        if ($labelled || trim($line) === '' || $this->structured($line) || $this->plainLevel($line) !== null || preg_match(self::FRAME_ONLY, $line) !== 1) {
            return false;
        }
        ++$this->orphanTraceLines;

        return true;
    }

    /**
     * Does `$line` continue the record `$open` (which it would be appended to)?
     * A `[timestamp]` line or JSON object always starts a record; so does a line with a level prefix.
     * Under an open Laravel/JSON record everything else continues it (its context and trace may hold any text);
     * under an open plain record frame-shaped lines do, and so does anything (capped) once the record is a head-anchored trace.
     */
    public function continues(string $line, string $open): bool
    {
        if ($this->structured($line) || $this->plainLevel($line) !== null) {
            return false;
        }
        $nl = strpos($open, "\n");
        if ($this->structured($nl === false ? $open : substr($open, 0, $nl))) {
            return true;
        }

        if (preg_match(self::CONTINUATION, $line) === 1) {
            return true;
        }

        // Trace mode: only a record whose first line is a crash head takes unrecognised lines, up to the cap.
        if (preg_match(self::TRACE_HEAD, $nl === false ? $open : substr($open, 0, $nl)) !== 1) {
            return false;
        }
        if (substr_count($open, "\n") >= self::TRACE_ABSORB_CAP) {
            ++$this->traceLinesCapped;

            return false;
        }

        return true;
    }

    /** Does this physical line begin a `[timestamp]` text record or a JSON object? */
    private function structured(string $line): bool
    {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '{')) {
            return is_array(json_decode($trimmed, true));
        }
        $m = $this->text($line);

        return $m !== null && $this->time($m['dt']) !== null;
    }

    /**
     * @param ?DateTimeImmutable $fallbackTime used when the line carries no usable time (default: now, UTC)
     * @param string             $line         one record; continuation lines (stack trace) follow the first, joined by "\n"
     */
    public function parse(string $line, ?DateTimeImmutable $fallbackTime = null): LogEvent
    {
        $fallback = $fallbackTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '{')) {
            $data = json_decode($trimmed, true);
            if (is_array($data)) {
                return $this->withException($this->fromJson($data, $fallback), '');
            }
        }

        $nl = strpos($line, "\n");
        $head = $nl === false ? $line : substr($line, 0, $nl);
        $rest = $nl === false ? '' : substr($line, $nl + 1);

        $m = $this->text($head);
        // Laravel leaves the context JSON open on the first line when the exception trace has line breaks.
        if ($rest !== '' && ($m === null || $this->ctx($m['ctx'] ?? '') === null)) {
            $whole = $this->text($line, 's');
            if ($whole !== null && $this->ctx($whole['ctx'] ?? '') !== null) {
                $m = $whole;
            }
        }
        $time = $m === null ? null : $this->time($m['dt']);
        if ($m !== null && $time !== null) {
            $ctx = $this->ctx($m['ctx'] ?? '');

            return $this->withException(new LogEvent(
                $time,
                $this->level($m['lvl']),
                // First physical line only: stack lines can hold call arguments (names, e-mails) and never belong in the message.
                rtrim(explode("\n", $m['msg'], 2)[0]),
                null,
                [],
                $ctx !== null ? $this->flatten($ctx, '', []) : [],
                [],
            ), $rest);
        }

        return $this->withException(new LogEvent($fallback, $this->plainLevel($head) ?? Level::Info, $head, null, [], [], []), $rest);
    }

    /**
     * Context JSON; Monolog writes a trace into it with raw line breaks, which strict JSON rejects.
     *
     * @return ?array<mixed>
     */
    private function ctx(string $raw): ?array
    {
        $raw = trim($raw);
        $data = json_decode($raw, true) ?? json_decode(str_replace("\n", '\\n', $raw), true);

        return is_array($data) ? $data : null;
    }

    /** Level of a token that was present in the line; counts the ones we cannot read. */
    private function level(string $token): Level
    {
        $level = Level::tryParse($token);
        if ($level === null && trim($token) !== '') {
            ++$this->unknownLevels;
        }

        return $level ?? Level::Info;
    }

    /**
     * Numbers: pino's scale for `level`; RFC 5424 0-7 only under the `severity` key. Small ints under `level`
     * are not guessed (they count as unknown).
     */
    private function jsonLevel(mixed $value, ?string $key): Level
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return Level::Info;
        }
        if ($key === 'severity' && is_numeric($value) && ($syslog = Level::fromSyslog((int) $value)) !== null) {
            return $syslog;
        }

        return $this->level((string) $value);
    }

    private function plainLevel(string $line): ?Level
    {
        if (preg_match(self::SYSLOG_PRI, $line, $m) === 1) {
            return Level::fromSyslog((int) $m['n'] % 8);
        }
        if (preg_match(self::PLAIN_LEVEL, $line, $m) === 1 || preg_match(self::LOGFMT_LEVEL, $line, $m) === 1) {
            return Level::tryParse($m['w']);
        }

        return null;
    }

    /** @return ?array<string|int, string> */
    private function text(string $line, string $flags = ''): ?array
    {
        return preg_match(self::TEXT . $flags, $line, $m) === 1 ? $m : null;
    }

    /**
     * Fills errorType and `path:line` frames (never messages or call arguments) from the Monolog
     * `[object] (Class(code: N): msg at path:line)` form, the `Class: msg in path:line` message form
     * and `#N path(line): call` trace lines. Only when the event has neither yet.
     * ponytail: vendor frames are kept; the 5-frame cap is the only filter.
     */
    private function withException(LogEvent $e, string $rest): LogEvent
    {
        if ($e->errorType !== null || $e->frames !== []) {
            return $e;
        }
        $exception = is_string($e->fields['exception'] ?? null) ? $e->fields['exception'] : '';
        $type = null;
        $frames = [];

        if (preg_match(self::OBJECT_FORM, $exception, $m) === 1) {
            $type = $m['t'];
            if (isset($m['f'], $m['l'])) {
                $frames[] = $m['f'] . ':' . $m['l'];
            }
        } elseif (preg_match(self::MESSAGE_FORM, $e->message, $m) === 1) {
            $type = $m['t'];
            $frames[] = $m['f'] . ':' . $m['l'];
        }
        if (preg_match_all(self::TRACE_LINE, $exception . "\n" . $rest, $t, PREG_SET_ORDER) > 0) {
            foreach ($t as $x) {
                $frames[] = $x['f'] . ':' . $x['l'];
            }
        }
        if ($type === null && $frames === []) {
            return $e;
        }

        $type = self::classNameOrNull($type);
        if ($type === null && $frames === []) {
            return $e;
        }

        return new LogEvent($e->time, $e->level, $e->message, $type, array_values(array_unique($frames)), $e->fields, $e->labels);
    }

    /** Names inside a valid class name (`AnnaKowalskiNotFoundException`) cannot be detected; see docs/privacy.md. */
    public static function classNameOrNull(?string $type): ?string
    {
        return $type !== null && strlen($type) <= self::CLASS_NAME_MAX && preg_match(self::CLASS_NAME, $type) === 1 ? $type : null;
    }

    /** @param array<mixed> $data */
    private function fromJson(array $data, DateTimeImmutable $fallback): LogEvent
    {
        $used = [];
        // `{"status":500}` is an HTTP status; `status` is a level only as a Datadog-style word.
        $keys = isset($data['status']) && (!is_string($data['status']) || is_numeric($data['status']))
            ? array_values(array_diff(self::LEVEL_KEYS, ['status']))
            : self::LEVEL_KEYS;
        $level = $this->pick($data, $keys, $used);
        $levelKey = $used[0] ?? null;
        $message = $this->pick($data, self::MESSAGE_KEYS, $used);
        $type = $this->pick($data, self::TYPE_KEYS, $used);
        $trace = $this->pick($data, self::TRACE_KEYS, $used);
        $time = $this->pick($data, self::TIME_KEYS, $used);

        $frames = is_string($trace) ? explode("\n", $trace) : (is_array($trace) ? $trace : []);
        $frames = array_values(array_filter(
            array_map(static fn (mixed $f): string => is_scalar($f) ? trim((string) $f) : '', $frames),
            static fn (string $f): bool => $f !== '',
        ));

        return new LogEvent(
            $this->time($time) ?? $fallback,
            $this->jsonLevel($level, $levelKey),
            is_scalar($message) ? (string) $message : '',
            is_scalar($type) ? self::classNameOrNull((string) $type) : null,
            $frames,
            $this->flatten($data, '', $used),
            [],
        );
    }

    /**
     * First present key wins; accepts a literal dotted key or a nested path. Records the path as used.
     *
     * @param array<mixed>  $data
     * @param list<string>  $keys
     * @param list<string>  $used
     */
    private function pick(array $data, array $keys, array &$used): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $used[] = $key;

                return $data[$key];
            }
            $node = $data;
            foreach (explode('.', $key) as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) {
                    continue 2;
                }
                $node = $node[$part];
            }
            $used[] = $key;

            return $node;
        }

        return null;
    }

    /**
     * @param array<mixed>  $data
     * @param list<string>  $skip dotted paths already consumed
     *
     * @return array<string, scalar|null>
     */
    private function flatten(array $data, string $prefix, array $skip): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $path = $prefix . $k;
            if (in_array($path, $skip, true)) {
                continue;
            }
            if (is_array($v)) {
                $out += $this->flatten($v, $path . '.', $skip);
            } elseif (is_scalar($v) || $v === null) {
                $out[$path] = $v;
            }
        }

        return $out;
    }

    /**
     * ISO string, or an epoch in seconds, milliseconds, microseconds or nanoseconds (int, float or numeric
     * string; told apart by magnitude). Anything outside 2000-01-01 .. now+1 day is null, never year 55000.
     */
    private function time(mixed $raw): ?DateTimeImmutable
    {
        try {
            $utc = new DateTimeZone('UTC');
            if (is_string($raw) && is_numeric($raw)) {
                $raw = (float) $raw;
            }
            if (is_int($raw) || is_float($raw)) {
                $n = (float) $raw;
                $seconds = $n / match (true) {
                    abs($n) < 1e11 => 1.0,
                    abs($n) < 1e14 => 1e3,
                    abs($n) < 1e17 => 1e6,
                    default => 1e9,
                };

                return $this->inRange($seconds) ? (new DateTimeImmutable('@' . (int) floor($seconds)))->setTimezone($utc) : null;
            }
            if (is_string($raw) && $raw !== '') {
                $t = (new DateTimeImmutable($raw, $utc))->setTimezone($utc);

                return $this->inRange($t->getTimestamp()) ? $t : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    private function inRange(float|int $seconds): bool
    {
        return $seconds >= 946_684_800 && $seconds <= time() + 86_400;
    }
}
