<?php

declare(strict_types=1);

namespace Watcher\Incident;

use Watcher\Notify\DiscordText;
use Watcher\Notify\SlackText;
use Watcher\Notify\TelegramText;

final class KnownLineRenderer
{
    /** @param list<KnownLine> $lines */
    public static function format(array $lines, string $driver = 'slack'): string
    {
        if ($lines === []) {
            return '';
        }
        $shown = array_slice($lines, 0, KnownIssues::MAX_LINES);
        $parts = [];
        foreach ($shown as $line) {
            $ticket = match ($driver) {
                'telegram' => TelegramText::plain($line->ticket),
                'discord' => DiscordText::plain($line->ticket),
                default => SlackText::escape($line->ticket),
            };
            $parts[] = sprintf('%s ×%s %s', $ticket, number_format($line->count), $line->level->value);
        }
        $more = count($lines) - count($shown);
        if ($more > 0) {
            $parts[] = $more . ' more';
        }

        return '📌 known (' . count($lines) . '): ' . implode(' · ', $parts);
    }
}
