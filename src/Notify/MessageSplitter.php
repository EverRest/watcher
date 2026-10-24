<?php

declare(strict_types=1);

namespace Watcher\Notify;

use Closure;

/**
 * Packs rendered sections into posts under a character limit. Splits only between sections; a section that is too
 * long is re-rendered from a shorter SOURCE (never cut as rendered markup) and marked. At most 10 parts.
 */
final class MessageSplitter
{
    public const MAX_PARTS = 10;
    private const RESERVE = 8; // "(10/10) "

    /**
     * @param list<Closure(int): string> $sections render the section from its source capped at N characters (PHP_INT_MAX = whole)
     * @param string $cutMarker already rendered for the channel
     *
     * @return list<string> final texts, numbered "(n/m) " when there is more than one
     */
    public static function split(array $sections, int $limit, string $cutMarker): array
    {
        if ($sections === []) {
            return [];
        }
        $full = array_map(static fn (Closure $r): string => $r(PHP_INT_MAX), $sections);

        $parts = self::pack($sections, $full, $limit, $cutMarker);
        if (count($parts) === 1) {
            return [implode("\n\n", $parts[0]['texts'])];
        }
        $parts = self::pack($sections, $full, $limit - self::RESERVE, $cutMarker);
        $texts = array_map(static fn (array $p): string => implode("\n\n", $p['texts']), $parts);
        if (count($texts) > self::MAX_PARTS) {
            $placed = 0;
            foreach (array_slice($parts, 0, self::MAX_PARTS - 1) as $p) {
                $placed += $p['count'];
            }
            $texts = [...array_slice($texts, 0, self::MAX_PARTS - 1), '… ' . (count($sections) - $placed) . ' more sections not shown'];
        }
        $m = count($texts);

        return array_map(static fn (string $t, int $i): string => sprintf('(%d/%d) ', $i + 1, $m) . $t, $texts, array_keys($texts));
    }

    /**
     * @param list<Closure(int): string> $sections
     * @param list<string> $full
     *
     * @return list<array{texts: list<string>, count: int}>
     */
    private static function pack(array $sections, array $full, int $limit, string $cutMarker): array
    {
        $parts = [];
        $texts = [];
        $size = 0;
        foreach ($full as $i => $text) {
            if (mb_strlen($text) > $limit) {
                $text = self::cut($sections[$i], $text, $limit, $cutMarker);
            }
            $add = mb_strlen($text) + ($texts === [] ? 0 : 2);
            if ($texts !== [] && $size + $add > $limit) {
                $parts[] = ['texts' => $texts, 'count' => count($texts)];
                $texts = [];
                $size = 0;
                $add = mb_strlen($text);
            }
            $texts[] = $text;
            $size += $add;
        }
        $parts[] = ['texts' => $texts, 'count' => count($texts)];

        return $parts;
    }

    /** @param Closure(int): string $render */
    private static function cut(Closure $render, string $full, int $limit, string $marker): string
    {
        $fits = static fn (int $n): ?string => mb_strlen($t = $render($n) . ' ' . $marker) <= $limit ? $t : null;
        // Source can be a few times longer than its rendering (entities), so search up to 5x the rendered length.
        [$lo, $hi] = [0, 5 * mb_strlen($full)];
        if ($fits(0) === null) {
            return mb_substr($marker, 0, $limit);
        }
        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            $fits($mid) === null ? $hi = $mid : $lo = $mid;
        }

        return (string) $fits($lo);
    }
}
