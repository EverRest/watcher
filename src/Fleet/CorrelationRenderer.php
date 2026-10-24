<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Notify\DigestRenderer;
use Watcher\Notify\Message;
use Watcher\Notify\SlackText;
use Watcher\Privacy\GroupStatusLabel;

/** Renders a correlated finding from a code template (no model, spec 003 R4). Sections: header, finding, footer. */
final class CorrelationRenderer
{
    private const MAX_TEMPLATE_CHARS = 300;

    public function render(CorrelatedFinding $f, string $timezone): Message
    {
        $tz = new DateTimeZone($timezone);
        $fmt = static fn (DateTimeImmutable $t): string => $t->setTimezone($tz)->format('j M H:i');
        $origin = SlackText::escape($f->originService);
        $o = $f->origin;

        $header = sprintf('🔗 *Probable upstream cause: %s* (hypothesis) · %s · %s - %s', $origin, SlackText::escape($f->fleet), $fmt($f->windowStart), $fmt($f->windowEnd));
        $trend = match ($o->status) {
            GroupStatusLabel::Grown => sprintf('grown %s -> %s', number_format($o->previousCount ?? 0), number_format($o->count)),
            default => sprintf('new, %s events', number_format($o->count)),
        };
        $template = SlackText::escape(mb_substr($o->template ?? '(no template)', 0, self::MAX_TEMPLATE_CHARS));
        $head = sprintf("`%s`  ·  %s · %s in `%s` (%s)\n", $template, strtolower($o->level->name), $trend, $origin, substr($o->fingerprint, 0, 12));
        $lead = sprintf('Affected consumers (they reference `%s` in the same window): ', $origin);

        $parts = array_map(static fn (array $c): string => sprintf('%s %s events (%d group%s)', SlackText::escape($c['service']), number_format($c['count']), $c['groups'], $c['groups'] === 1 ? '' : 's'), $f->consumers);
        $shown = count($parts);
        while (true) {
            $more = count($parts) - $shown;
            $line = $head . $lead . implode(', ', array_slice($parts, 0, $shown)) . ($more > 0 ? ($shown > 0 ? ', ' : '') . "+$more more services" : '');
            if (mb_strlen($line) <= DigestRenderer::MAX_SECTION_CHARS || $shown === 0) {
                break;
            }
            $shown = max(0, $shown - max(1, intdiv($shown, 10)));
        }

        $sections = [
            ['kind' => 'header', 'text' => $header],
            ['kind' => 'finding', 'text' => mb_substr($line, 0, DigestRenderer::MAX_SECTION_CHARS)],
            ['kind' => 'footer', 'text' => "Each consumer's own digest still contains its finding, annotated with this origin."],
        ];

        return new Message(mb_substr(SlackText::plain(implode("\n", array_column($sections, 'text'))), 0, DigestRenderer::MAX_TEXT_CHARS), $sections);
    }
}
