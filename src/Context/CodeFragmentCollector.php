<?php

declare(strict_types=1);

namespace Watcher\Context;

use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\Redactor;

final class CodeFragmentCollector
{
    /**
     * @param array{max_frames: int, max_lines: int, max_bytes_per_fragment: int, max_bytes_per_group: int, max_file_bytes: int} $limits
     */
    public function __construct(
        private readonly CodeRepository $repository,
        private readonly CodeLinkBuilder $links,
        private readonly Redactor $redactor,
        private readonly string $commit,
        private readonly array $limits,
    ) {
    }

    /**
     * @param list<RedactedGroup> $groups
     *
     * @return array<string, list<array{path: string, line: int, partial: bool, body: string}>>
     */
    public function forGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $frags = $this->forGroup($g);
            if ($frags !== []) {
                $out[$g->fingerprint] = $frags;
            }
        }

        return $out;
    }

    /**
     * @return list<array{path: string, line: int, partial: bool, body: string}>
     */
    private function forGroup(RedactedGroup $g): array
    {
        $frags = [];
        $groupBytes = 0;
        $seen = [];
        foreach ($g->frames as $frame) {
            if (count($frags) >= $this->limits['max_frames']) {
                break;
            }
            $resolved = $this->links->resolveFrame($frame);
            if ($resolved === null) {
                continue;
            }
            $key = $resolved['path'] . ':' . $resolved['line'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            try {
                $file = $this->repository->readFile($this->commit, $resolved['path'], $this->limits['max_file_bytes']);
            } catch (CodeRepositoryException) {
                continue;
            }
            [$body, $partial] = $this->window($file, $resolved['line']);
            $redacted = $this->redactor->redactText($body, false);
            if ($redacted === null) {
                continue;
            }
            if (strlen($redacted) > $this->limits['max_bytes_per_fragment']) {
                $redacted = substr($redacted, 0, $this->limits['max_bytes_per_fragment']);
                $partial = true;
            }
            if ($groupBytes + strlen($redacted) > $this->limits['max_bytes_per_group']) {
                break;
            }
            $groupBytes += strlen($redacted);
            $frags[] = ['path' => $resolved['path'], 'line' => $resolved['line'], 'partial' => $partial, 'body' => $redacted];
        }

        return $frags;
    }

    /** @return array{0: string, 1: bool} */
    private function window(string $file, int $line): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $file) ?: [];
        $max = $this->limits['max_lines'];
        $idx = max(0, min(count($lines) - 1, $line - 1));
        $half = intdiv($max, 2);
        $start = max(0, $idx - $half);
        $end = min(count($lines), $start + $max);
        $start = max(0, $end - $max);
        $slice = array_slice($lines, $start, $end - $start);
        $partial = $start > 0 || $end < count($lines);

        return [implode("\n", $slice), $partial];
    }
}
