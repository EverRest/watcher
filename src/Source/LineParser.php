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

    private const LEVEL_KEYS = ['level', 'severity', 'status'];
    private const MESSAGE_KEYS = ['message', 'msg'];
    private const TYPE_KEYS = ['exception.class', 'error.kind'];
    private const TRACE_KEYS = ['exception.trace', 'error.stack'];
    private const TIME_KEYS = ['time', 'timestamp', '@timestamp', 'datetime', 'ts'];

    /** @param ?DateTimeImmutable $fallbackTime used when the line carries no usable time (default: now, UTC) */
    public function parse(string $line, ?DateTimeImmutable $fallbackTime = null): LogEvent
    {
        $fallback = $fallbackTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '{')) {
            $data = json_decode($trimmed, true);
            if (is_array($data)) {
                return $this->fromJson($data, $fallback);
            }
        }

        if (preg_match(self::TEXT, $line, $m) === 1) {
            $time = $this->time($m['dt']);
            if ($time !== null) {
                $ctx = json_decode(trim($m['ctx'] ?? ''), true);

                return new LogEvent(
                    $time,
                    Level::parse($m['lvl']),
                    $m['msg'],
                    null,
                    [],
                    is_array($ctx) ? $this->flatten($ctx, '', []) : [],
                    [],
                );
            }
        }

        return new LogEvent($fallback, Level::Info, $line, null, [], [], []);
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
