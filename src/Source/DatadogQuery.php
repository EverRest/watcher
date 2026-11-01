<?php

declare(strict_types=1);

namespace Watcher\Source;

use InvalidArgumentException;
use Watcher\Level;
use Watcher\Schedule\Window;

/** The Datadog search text and time window of a read: tag terms, the level and watch-pattern filter, and the from/to pair. Pure. */
final class DatadogQuery
{
    /**
     * `AND (status:(...) OR "term" ...)`, or '' when it cannot narrow. A watch pattern that is not a plain
     * literal cannot be expressed in Datadog search, so it disables the server filter (client side still applies).
     */
    public static function readQuery(ReadFilter $filter, string $levelMode): string
    {
        if ($filter->minLevel === Level::Debug || $levelMode === 'all') {
            return '';
        }
        $statuses = [];
        foreach (Level::cases() as $level) {
            if ($level->atLeast($filter->minLevel)) {
                $statuses[] = $level === Level::Warning ? 'warn' : strtolower($level->name);
            }
        }
        $parts = ['status:(' . implode(' OR ', $statuses) . ')'];
        foreach ($filter->watchPatterns as $re) {
            $literal = self::literal($re);
            if ($literal === null) {
                return '';
            }
            $parts[] = '*' . $literal . '*';
        }

        return ' AND (' . implode(' OR ', $parts) . ')';
    }

    /** The text of a pattern that is a plain alphanumeric/underscore word (usable as a `*word*` wildcard), else null. */
    public static function literal(string $re): ?string
    {
        $body = str_starts_with($re, '(?i)') ? substr($re, 4) : $re;

        return preg_match('/^\w+$/D', $body) === 1 ? $body : null;
    }

    public static function tagQuery(StreamSelector $selector): string
    {
        $terms = [];
        foreach ($selector->tags as $key => $value) {
            if (preg_match('/^[A-Za-z0-9_.@\/-]+$/D', (string) $key) !== 1) {
                throw new InvalidArgumentException("Invalid Datadog tag key '{$key}'.");
            }
            $terms[] = $key . ':' . (preg_match('/^[A-Za-z0-9_.\/-]+$/D', $value) === 1 ? $value : self::quote($value));
        }
        if ($terms === []) {
            throw new InvalidArgumentException('A Datadog query needs at least one stream tag.');
        }

        return implode(' ', $terms);
    }

    public static function quote(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . '"';
    }

    /** @return array{from: string, to: string, query: string} */
    public static function filter(Window $window, string $query): array
    {
        return ['from' => $window->start->format('Y-m-d\TH:i:s.v\Z'), 'to' => $window->end->format('Y-m-d\TH:i:s.v\Z'), 'query' => $query];
    }
}
