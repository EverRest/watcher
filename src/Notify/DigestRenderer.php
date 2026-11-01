<?php

declare(strict_types=1);

namespace Watcher\Notify;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;

/**
 * Renders the digest (contracts/slack-messages.md) into a neutral Message.
 *
 * Message::$sections kinds, in order: header, then per severity a severity heading followed by its
 * findings (kind "severity", "finding"), then optional "minor" and "notice" lines.
 * Message::$text is a plain fallback (title line + counts), at most 4,000 characters.
 * Section text is Slack mrkdwn with every model-provided string escaped.
 */
final class DigestRenderer
{
    public const MAX_SECTIONS = 40;
    public const MAX_SECTION_CHARS = 2800;
    public const MAX_TEXT_CHARS = 4000;

    public function render(DigestData $d, float $confidenceThreshold): Message
    {
        $shown = [];
        $minor = 0;
        foreach ($d->findings as $i => $f) {
            if (self::isMinor($f, $confidenceThreshold)) {
                ++$minor;
                continue;
            }
            $shown[] = ['f' => $f, 'n' => self::count($f, $d), 'i' => $i];
        }
        usort($shown, fn (array $a, array $b): int => [self::rank($b['f']->severity), $b['n'], $a['i']]
            <=> [self::rank($a['f']->severity), $a['n'], $b['i']]);

        // Never split: overflow moves the lowest-severity findings into the minor line.
        while (count($sections = DigestSections::build($d, $shown, $minor)) > DigestRenderer::MAX_SECTIONS && $shown !== []) {
            array_pop($shown);
            ++$minor;
        }

        $header = DigestSections::header($d);
        $counts = count($shown) . ' findings' . ($minor > 0 ? ", $minor minor" : '');

        return new Message(
            SlackText::cap(SlackText::plain($header) . "\n" . $counts, DigestRenderer::MAX_TEXT_CHARS),
            $sections
        );
    }

    public function renderBaseline(string $service, string $environment, int $groups, int $events): Message
    {
        $text = sprintf(
            '✅ watcher · %s · %s — baseline recorded: %s groups, %s events. Analysis starts next run.',
            SlackText::escape($service),
            SlackText::escape($environment),
            number_format($groups),
            number_format($events)
        );

        return new Message(SlackText::cap(SlackText::plain($text), DigestRenderer::MAX_TEXT_CHARS), [['kind' => 'notice', 'text' => $text]]);
    }

    public function renderFingerprintVersionReset(string $service, string $environment, int $groups, int $events, int $fromFpVersion, int $toFpVersion): Message
    {
        $text = sprintf(
            '✅ watcher · %s · %s — baseline reset: fingerprint rules changed (fpv %d → %d). Recorded %s groups, %s events; comparisons and cached verdicts start fresh next run.',
            SlackText::escape($service),
            SlackText::escape($environment),
            $fromFpVersion,
            $toFpVersion,
            number_format($groups),
            number_format($events)
        );

        return new Message(SlackText::cap(SlackText::plain($text), DigestRenderer::MAX_TEXT_CHARS), [['kind' => 'notice', 'text' => $text]]);
    }

    private static function isMinor(Finding $f, float $threshold): bool
    {
        if ($f->severity === Severity::Error || $f->severity === Severity::Critical) {
            return false;
        }

        return $f->kind === FindingKind::Noise || $f->confidence < $threshold;
    }

    private static function rank(Severity $s): int
    {
        return match ($s) {
            Severity::Critical => 3,
            Severity::Error => 2,
            Severity::Warn => 1,
            Severity::Info => 0,
        };
    }

    private static function count(Finding $f, DigestData $d): int
    {
        $n = 0;
        foreach ($f->fingerprints as $fp) {
            $n += isset($d->groups[$fp]) ? $d->groups[$fp]->count : 0;
        }

        return $n;
    }

    public static function formatGapLine(DateTimeImmutable $from, DateTimeImmutable $to, ?int $gapWindows): string
    {
        $fmt = static fn (DateTimeImmutable $t): string => $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC';
        $fromS = $fmt($from);
        $toS = $fmt($to);
        if ($gapWindows === null) {
            return sprintf('⚠️ %s to %s: not analysed. Events in that span were not read.', $fromS, $toS);
        }

        return sprintf(
            '⚠️ %s to %s: not analysed (%d window(s) missing or failed). Events in that span were not read.',
            $fromS,
            $toS,
            $gapWindows,
        );
    }
}
