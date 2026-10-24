<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\Notify\DigestRenderer;
use Watcher\Notify\Message;
use Watcher\Notify\SlackText;

/**
 * One message for the whole fleet: a heading per reporting service followed by its own sections, quiet services in one
 * line. Never split: when the blocks do not fit, the services with the fewest findings collapse to one line, then the
 * rest to "N more services" (SC-005). Section text is already escaped by the renderer; service names are escaped here.
 */
final class CombinedDigest
{
    private const MAX_BLOCKS = 50;

    /**
     * @param array<string, Message> $digests service => its held digest, in run order
     * @param list<string> $quiet services that ran with nothing to report
     */
    public function build(string $fleet, array $digests, array $quiet): ?Message
    {
        if ($digests === []) {
            return null;
        }
        $items = [];
        foreach ($digests as $service => $message) {
            $name = SlackText::escape((string) $service);
            $body = array_values(array_filter($message->sections, static fn (array $s): bool => $s['kind'] !== 'header'));
            $n = count(array_filter($body, static fn (array $s): bool => $s['kind'] === 'finding'));
            $items[] = [
                'name' => $name,
                'n' => $n,
                'mode' => 'full',
                'full' => [['kind' => 'severity', 'text' => '*' . $name . '*'], ...$body],
                'line' => ['kind' => 'notice', 'text' => sprintf('*%s*: %d findings, see its digest', $name, $n)],
            ];
        }
        $title = sprintf('watcher · fleet %s — %d of %d services reported', SlackText::escape($fleet), count($digests), count($digests) + count($quiet));
        $quietLine = $quiet === [] ? [] : [['kind' => 'notice', 'text' => '✅ quiet: ' . implode(', ', array_map(SlackText::escape(...), $quiet))]];

        while (count($sections = $this->assemble($title, $items, $quietLine)) > self::MAX_BLOCKS) {
            $this->shrink($items);
        }

        $names = implode(', ', array_map(static fn (array $i): string => $i['name'], $items));
        $text = mb_substr(SlackText::plain($title) . "\n" . $names, 0, DigestRenderer::MAX_TEXT_CHARS);

        return new Message($text, $sections);
    }

    /**
     * @param list<array{name: string, n: int, mode: string, full: list<array{kind: string, text: string}>, line: array{kind: string, text: string}}> $items
     * @param list<array{kind: string, text: string}> $quietLine
     * @return list<array{kind: string, text: string}>
     */
    private function assemble(string $title, array $items, array $quietLine): array
    {
        $out = [['kind' => 'header', 'text' => $title]];
        $merged = 0;
        foreach ($items as $i) {
            if ($i['mode'] === 'full') {
                array_push($out, ...$i['full']);
            } elseif ($i['mode'] === 'line') {
                $out[] = $i['line'];
            } else {
                ++$merged;
            }
        }
        if ($merged > 0) {
            $out[] = ['kind' => 'notice', 'text' => $merged . ' more services: see their digests'];
        }

        return [...$out, ...$quietLine];
    }

    /**
     * Collapses the full service with the fewest findings (the later one on a tie); with none left, folds the last line.
     *
     * @param list<array{name: string, n: int, mode: string, full: list<array{kind: string, text: string}>, line: array{kind: string, text: string}}> $items
     */
    private function shrink(array &$items): void
    {
        $pick = null;
        foreach ($items as $k => $i) {
            if ($i['mode'] === 'full' && ($pick === null || $i['n'] <= $items[$pick]['n'])) {
                $pick = $k;
            }
        }
        if ($pick !== null) {
            $items[$pick]['mode'] = 'line';

            return;
        }
        foreach (array_reverse(array_keys($items)) as $k) {
            if ($items[$k]['mode'] === 'line') {
                $items[$k]['mode'] = 'merged';

                return;
            }
        }
    }
}
