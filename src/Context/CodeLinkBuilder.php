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

    /** @return array{path: string, line: int}|null */
    public function resolveFrame(string $frame): ?array
    {
        $map = $this->repo['path_map'] ?? null;
        if (!is_array($map) || $this->commit === null || $this->commit === ''
            || !preg_match('/^(.+):(\d+)$/D', $frame, $m)) {
            return null;
        }
        $path = str_starts_with($m[1], '/') || preg_match('~^(?:[A-Za-z]:|…|\.)~u', $m[1]) === 1 ? $this->mapPath($m[1], $map) : $m[1];
        if ($path === null || str_starts_with($path, 'vendor/') || str_contains($path, '/vendor/')) {
            return null;
        }
        if (in_array('..', explode('/', ltrim($path, '/')), true)) {
            return null;
        }
        return ['path' => $path, 'line' => (int) $m[2]];
    }

    public function forFrame(string $frame): ?string
    {
        $webUrl = $this->repo['web_url'] ?? null;
        $blob = match ($this->repo['provider'] ?? null) {
            'gitlab' => '/-/blob/',
            'github' => '/blob/',
            default => null,
        };
        $resolved = $this->resolveFrame($frame);
        if ($blob === null || !is_string($webUrl) || $webUrl === '' || $resolved === null) {
            return null;
        }
        $segments = explode('/', ltrim($resolved['path'], '/'));
        $commit = $this->commit ?? '';
        return rtrim($webUrl, '/') . $blob . rawurlencode($commit) . '/'
            . implode('/', array_map('rawurlencode', $segments)) . '#L' . $resolved['line'];
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
            if (!is_string($replacement) || $prefix === '' || !str_starts_with($path, $prefix)) {
                continue;
            }
            $after = strlen($prefix);
            if ($after < strlen($path) && !str_ends_with($prefix, '/') && $path[$after] !== '/') {
                continue;
            }
            if ($best === null || strlen($prefix) > strlen($best)) {
                $best = $prefix;
            }
        }

        if ($best === null) {
            return null;
        }
        $rest = substr($path, strlen($best));
        if ($rest !== '' && $rest[0] === '/') {
            $rest = substr($rest, 1);
        }

        return (string) $map[$best] . $rest;
    }
}
