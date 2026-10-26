<?php

declare(strict_types=1);

namespace Watcher\Mcp;

/**
 * FR-080..082, FR-086: the allowlisted package files as resources and a lexical search over their sections. In process, lazy, no
 * network, no model. A missing file is skipped; a stripped install gives a shorter list, never a failure.
 */
final class DocsIndex
{
    /** @var list<array{file: string, anchor: string|null, heading: string, line: int, text: string, terms: array<string, int>, head: array<string, int>}>|null */
    private ?array $sections = null;

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<string> allowlisted relative paths that exist and are small enough */
    public function files(): array
    {
        $out = [];
        foreach (array_keys(DocsAllowlist::FILES) as $f) {
            $p = $this->root . '/' . $f;
            if (is_file($p) && !is_link($p) && (int) filesize($p) <= DocsAllowlist::MAX_BYTES) {
                $out[] = $f;
            }
        }

        return $out;
    }

    /** @return list<array{uri: string, name: string, description: string, mimeType: string}> */
    public function resources(): array
    {
        return array_map(static fn (string $f): array => ['uri' => DocsAllowlist::SCHEME . $f, 'name' => $f, 'description' => 'Package documentation file ' . $f, 'mimeType' => DocsAllowlist::FILES[$f]], $this->files());
    }

    /**
     * @return array{uri: string, mimeType: string, text: string}
     * @throws InvalidParams
     */
    public function read(string $uri): array
    {
        $rel = str_starts_with($uri, DocsAllowlist::SCHEME) ? substr($uri, strlen(DocsAllowlist::SCHEME)) : null;
        if ($rel === null || preg_match('~^[A-Za-z0-9._/-]{1,120}$~D', $rel) !== 1 || str_contains($rel, '..') || str_starts_with($rel, '/')
            || !isset(DocsAllowlist::FILES[$rel]) || !in_array($rel, $this->files(), true)) {
            throw new InvalidParams('resource_not_found');
        }
        $text = file_get_contents($this->root . '/' . $rel);
        if ($text === false) {
            throw new InvalidParams('resource_not_found');
        }

        return ['uri' => $uri, 'mimeType' => DocsAllowlist::FILES[$rel], 'text' => $text];
    }

    /**
     * @return list<array{file: string, anchor: string|null, heading: string, snippet: string, score: int}>|null null when no document is installed
     * @throws InvalidParams
     */
    public function search(string $query, int $limit): ?array
    {
        $terms = array_values(array_unique(self::words($query)));
        if ($terms === [] || count($terms) > 8) {
            throw new InvalidParams('invalid params: query');
        }
        $sections = $this->sections();
        if ($sections === []) {
            return null;
        }
        $scored = [];
        foreach ($sections as $i => $s) {
            $score = 0;
            foreach ($terms as $t) {
                foreach ([$t, rtrim($t, 's')] as $variant) {
                    $score += ($s['terms'][$variant] ?? 0) + 5 * ($s['head'][$variant] ?? 0);
                    if ($variant === rtrim($t, 's')) {
                        break;
                    }
                }
            }
            if ($score > 0) {
                $scored[] = [$score, $s['file'], $s['line'], $i];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
        $out = [];
        foreach (array_slice($scored, 0, $limit) as [$score, , , $i]) {
            $s = $sections[$i];
            $out[] = ['file' => $s['file'], 'anchor' => $s['anchor'], 'heading' => mb_substr($s['heading'], 0, 120), 'snippet' => self::snippet($s['text'], $terms), 'score' => $score];
        }

        return $out;
    }

    /** @return list<array{file: string, anchor: string|null, heading: string, line: int, text: string, terms: array<string, int>, head: array<string, int>}> */
    private function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }
        $all = [];
        foreach ($this->files() as $f) {
            $text = (string) file_get_contents($this->root . '/' . $f);
            $md = str_ends_with($f, '.md');
            $cur = ['heading' => $f, 'anchor' => null, 'line' => 1, 'body' => []];
            $flush = function () use (&$cur, &$all, $f): void {
                $body = trim(implode("\n", $cur['body']));
                $all[] = ['file' => $f, 'anchor' => $cur['anchor'], 'heading' => $cur['heading'], 'line' => $cur['line'], 'text' => $body, 'terms' => array_count_values(self::words($body)), 'head' => array_count_values(self::words($cur['heading']))];
            };
            $fence = false;
            foreach (explode("\n", $text) as $n => $line) {
                if ($md && str_starts_with($line, '```')) {
                    $fence = !$fence;
                }
                if ($md && !$fence && preg_match('/^#{1,4} +(.+?)\s*#*$/', $line, $m) === 1) {
                    $flush();
                    $cur = ['heading' => $m[1], 'anchor' => self::slug($m[1]), 'line' => $n + 1, 'body' => []];
                    continue;
                }
                $cur['body'][] = $line;
            }
            $flush();
        }

        return $this->sections = $all;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        preg_match_all('/[a-z0-9_]{2,}/', strtolower($text), $m);

        return $m[0];
    }

    /** GitHub-style slug of a heading. */
    public static function slug(string $heading): string
    {
        $s = strtolower(str_replace('`', '', $heading));
        $s = (string) preg_replace('/[^a-z0-9 _-]/', '', $s);

        return str_replace(' ', '-', $s);
    }

    /** @param list<string> $terms */
    private static function snippet(string $body, array $terms): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $body)), static fn (string $l): bool => $l !== '' && !str_starts_with($l, '|---'));
        $hit = null;
        foreach ($lines as $l) {
            $low = strtolower($l);
            foreach ($terms as $t) {
                if (str_contains($low, $t)) {
                    $hit = $l;
                    break 2;
                }
            }
        }
        $s = (string) preg_replace('/\s+/', ' ', $hit ?? (string) reset($lines));

        return mb_strlen($s) > 240 ? mb_substr($s, 0, 239) . '…' : $s;
    }
}
