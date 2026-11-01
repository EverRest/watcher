<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use InvalidArgumentException;
use Watcher\Level;

/** The LogQL of a Loki read: the stream selector, the level matchers and line filter, and nanosecond timestamps. Pure. */
final class LokiQuery
{
    public const LEVEL_WORDS = [
        'debug' => ['debug'],
        'info' => ['info'],
        'notice' => ['notice'],
        'warning' => ['warn', 'warning'],
        'error' => ['err', 'error'],
        'critical' => ['crit', 'critical'],
        'alert' => ['alert'],
        'emergency' => ['emerg', 'emergency', 'fatal'],
    ];

    /** Extra spellings seen in a `level` stream label (not as words in the text). */
    public const LABEL_WORDS = ['info' => ['information'], 'emergency' => ['panic']];

    /**
     * LogQL `|~` alternation (RE2): stack-trace continuation lines with no level word (H5). Kept in sync with
     * {@see LineParser::continues()} shapes, not the full CONTINUATION regex.
     */
    public const TRACE_CONTINUATION_FILTER = '(?:^\\s|^#\\d{1,4}\\b|^at\\s|^Caused by:|^Stack trace:|^PHP (?:Stack trace:|\\d+\\.)|^Traceback|^goroutine \\d+ \\[|^created by |^exit status \\d|^stack backtrace:)';

    /** @return list<string> */
    public static function words(Level $min, bool $labelSpellings): array
    {
        $words = [];
        foreach (Level::cases() as $level) {
            if ($level->atLeast($min)) {
                $key = strtolower($level->name);
                array_push($words, ...self::LEVEL_WORDS[$key], ...($labelSpellings ? self::LABEL_WORDS[$key] ?? [] : []));
            }
        }

        return $words;
    }

    /** Stream matcher for the `level` label (all case variants), '' when every level is wanted. */
    public static function levelMatcher(ReadFilter $filter): string
    {
        return $filter->minLevel === Level::Debug ? '' : 'level=~' . self::quote('(?i)(?:' . implode('|', self::words($filter->minLevel, true)) . ')');
    }

    public static function lineFilter(ReadFilter $filter, string $levelMode): string
    {
        if ($levelMode !== 'text') {
            return '';
        }
        $parts = [];
        if ($filter->minLevel !== Level::Debug) {
            $parts[] = '(?:\b(?:' . implode('|', self::words($filter->minLevel, false)) . ')\b|' . self::TRACE_CONTINUATION_FILTER . ')';
        }
        foreach ($filter->watchPatterns as $re) {
            $parts[] = '(?:' . $re . ')';
        }
        if ($parts === []) {
            return '';
        }

        return ' |~ ' . self::quote('(?i)' . implode('|', $parts));
    }

    public static function selector(StreamSelector $selector, string $extra = ''): string
    {
        $matchers = [];
        foreach ($selector->tags as $key => $value) {
            $matchers[] = self::name((string) $key) . '=' . self::quote($value);
        }
        if ($matchers === []) {
            throw new InvalidArgumentException('A Loki query needs at least one stream tag.');
        }
        if ($extra !== '') {
            $matchers[] = $extra;
        }

        return '{' . implode(', ', $matchers) . '}';
    }

    public static function name(string $name): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException("Invalid Loki label name '{$name}'.");
        }

        return $name;
    }

    public static function quote(string $value): string
    {
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
    }

    public static function ns(DateTimeImmutable $t): string
    {
        return (string) self::nsInt($t);
    }

    public static function nsInt(DateTimeImmutable $t): int
    {
        return $t->getTimestamp() * 1_000_000_000 + (int) $t->format('u') * 1000;
    }
}
