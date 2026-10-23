<?php

declare(strict_types=1);

namespace Watcher\Context;

use Watcher\Level;
use Watcher\Privacy\RedactedGroup;
use Watcher\Schedule\Window;

/**
 * Deep links into the log backend (FR-042). Principle I: built ONLY from config, stream tags, level, window and the
 * redacted template; no raw event data is ever an input.
 */
final class LogLinkBuilder
{
    private const MAX_LITERAL = 60;
    private const MIN_LITERAL = 4;

    /**
     * @param array<string, mixed>  $links links config (grafana, datadog)
     * @param array<string, string> $tags  stream tags
     */
    public function __construct(private readonly array $links, private readonly array $tags)
    {
    }

    public function forGroup(RedactedGroup $g, Window $window): ?string
    {
        $literal = self::literal($g->template);
        $grafana = $this->links['grafana'] ?? null;
        $datadog = $this->links['datadog'] ?? null;

        if (is_array($grafana) && self::set($grafana['base_url'] ?? null) && self::set($grafana['datasource_uid'] ?? null)) {
            return $this->grafana((string) $grafana['base_url'], (string) $grafana['datasource_uid'], $g->level, $literal, $window);
        }
        if (is_array($datadog) && self::set($datadog['app_url'] ?? null)) {
            return $this->datadog((string) $datadog['app_url'], $literal, $window);
        }

        return null;
    }

    /**
     * UNVERIFIED: the Grafana Explore URL shape (`schemaVersion=1&panes=<json>`) is version dependent; this is the
     * only place that knows it. Pinned by a golden test, not by a live instance.
     */
    private function grafana(string $base, string $uid, Level $level, ?string $literal, Window $window): string
    {
        $expr = '{' . implode(',', array_map(
            fn (string $k, string $v): string => $k . '="' . self::esc($v) . '"',
            array_keys($this->tags),
            $this->tags,
        )) . '}';
        if ($literal !== null) {
            $expr .= ' |= "' . self::esc($literal) . '"';
        }
        if ($level->atLeast(Level::Error)) {
            $expr .= ' |~ "(?i)error|critical|alert|emergency"';
        }

        $panes = json_encode(['a' => [
            'datasource' => $uid,
            'queries' => [['refId' => 'A', 'expr' => $expr, 'datasource' => ['type' => 'loki', 'uid' => $uid]]],
            'range' => ['from' => $window->start->format('Uv'), 'to' => $window->end->format('Uv')],
        ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return rtrim($base, '/') . '/explore?schemaVersion=1&panes=' . rawurlencode($panes) . '&orgId=1';
    }

    private function datadog(string $appUrl, ?string $literal, Window $window): string
    {
        $parts = [];
        foreach ($this->tags as $k => $v) {
            $parts[] = $k . ':' . (preg_match('~^[\w.\-/]+$~', $v) === 1 ? $v : '"' . self::esc($v) . '"');
        }
        if ($literal !== null) {
            $parts[] = '"' . self::esc($literal) . '"';
        }

        return rtrim($appUrl, '/') . '/logs?query=' . rawurlencode(implode(' ', $parts))
            . '&from_ts=' . $window->start->format('Uv') . '&to_ts=' . $window->end->format('Uv');
    }

    /** Longest run of the redacted template holding no `<...>` placeholder; null when shorter than MIN_LITERAL. */
    private static function literal(?string $template): ?string
    {
        if ($template === null) {
            return null;
        }
        $best = '';
        foreach (preg_split('/<[^<>]*>|\R/u', $template) ?: [] as $run) {
            $run = trim($run);
            if (mb_strlen($run) > mb_strlen($best)) {
                $best = $run;
            }
        }
        $best = trim(mb_substr($best, 0, self::MAX_LITERAL));

        return mb_strlen($best) >= self::MIN_LITERAL ? $best : null;
    }

    private static function esc(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '"' => '\\"']);
    }

    private static function set(mixed $v): bool
    {
        return is_string($v) && $v !== '';
    }
}
