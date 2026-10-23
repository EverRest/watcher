<?php

declare(strict_types=1);

namespace Watcher\Context;

use Watcher\Privacy\RedactedGroup;

/** Turns redacted `path:line` frames into repository permalinks (FR-043). Built from config and the commit only. */
final class CodeLinkBuilder
{
    private const MAX_LINKS = 3;

    /** @param array<string, mixed> $repo provider (gitlab|github), web_url, path_map */
    public function __construct(private readonly array $repo, private readonly ?string $commit)
    {
    }

    public function forFrame(string $frame): ?string
    {
        $webUrl = $this->repo['web_url'] ?? null;
        $map = $this->repo['path_map'] ?? null;
        $blob = match ($this->repo['provider'] ?? null) {
            'gitlab' => '/-/blob/',
            'github' => '/blob/',
            default => null,
        };
        if ($blob === null || !is_string($webUrl) || $webUrl === '' || !is_array($map) || $this->commit === null || $this->commit === ''
            || !preg_match('/^(.+):(\d+)$/', $frame, $m)) {
            return null;
        }

        $path = $this->mapPath($m[1], $map);
        if ($path === null || str_starts_with($path, 'vendor/') || str_contains($path, '/vendor/')) {
            return null;
        }
        $segments = explode('/', ltrim($path, '/'));
        if (in_array('..', $segments, true)) {
            return null;
        }

        return rtrim($webUrl, '/') . $blob . rawurlencode($this->commit) . '/'
            . implode('/', array_map('rawurlencode', $segments)) . '#L' . $m[2];
    }

    /** @return list<string> */
    public function forGroup(RedactedGroup $g): array
    {
        $links = [];
        foreach ($g->frames as $frame) {
            $link = $this->forFrame($frame);
            if ($link !== null) {
                $links[] = $link;
                if (count($links) === self::MAX_LINKS) {
                    break;
                }
            }
        }

        return $links;
    }

    /** @param array<mixed> $map */
    private function mapPath(string $path, array $map): ?string
    {
        $best = null;
        foreach ($map as $prefix => $replacement) {
            $prefix = (string) $prefix;
            if (is_string($replacement) && $prefix !== '' && str_starts_with($path, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? null : (string) $map[$best] . substr($path, strlen($best));
    }
}
