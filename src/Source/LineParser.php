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

    private const LEVEL_KEYS = ['level', 'severity', 'status'];
    private const MESSAGE_KEYS = ['message', 'msg'];
    private const TYPE_KEYS = ['exception.class', 'error.kind'];
    private const TRACE_KEYS = ['exception.trace', 'error.stack'];
    private const TIME_KEYS = ['time', 'timestamp', '@timestamp', 'datetime', 'ts'];

    /** Does this physical line begin a new record (`[timestamp]` text or a JSON object)? */
    public function startsRecord(string $line): bool
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
                Level::parse($m['lvl']),
                // First physical line only: stack lines can hold call arguments (names, e-mails) and never belong in the message.
                rtrim(explode("\n", $m['msg'], 2)[0]),
                null,
                [],
                $ctx !== null ? $this->flatten($ctx, '', []) : [],
                [],
            ), $rest);
        }

        return $this->withException(new LogEvent($fallback, Level::Info, $head, null, [], [], []), $rest);
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

        return new LogEvent($e->time, $e->level, $e->message, $type, array_values(array_unique($frames)), $e->fields, $e->labels);
    }

    /** @param array<mixed> $data */
    private function fromJson(array $data, DateTimeImmutable $fallback): LogEvent
    {
        $used = [];
        $level = $this->pick($data, self::LEVEL_KEYS, $used);
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
            Level::parse(is_scalar($level) ? (string) $level : ''),
            is_scalar($message) ? (string) $message : '',
            is_scalar($type) && (string) $type !== '' ? (string) $type : null,
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

    private function time(mixed $raw): ?DateTimeImmutable
    {
        try {
            $utc = new DateTimeZone('UTC');
            if (is_int($raw) || is_float($raw)) {
                return (new DateTimeImmutable('@' . (int) $raw))->setTimezone($utc);
            }
            if (is_string($raw) && $raw !== '') {
                return (new DateTimeImmutable($raw, $utc))->setTimezone($utc);
            }
        } catch (Throwable) {
        }

        return null;
    }
}
