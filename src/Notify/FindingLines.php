<?php

declare(strict_types=1);

namespace Watcher\Notify;

use Watcher\Analyze\Finding;
use Watcher\Privacy\GroupStatusLabel;

/** One finding as Slack text: its title line, advice, links and the annotation and endpoint lines. Internal to DigestRenderer. */
final class FindingLines
{
    private const MAX_LINKS_CHARS = 1800;

    public static function finding(Finding $f, int $count, DigestData $d): string
    {
        $trend = '';
        $group = self::primaryGroup($f, $d);
        if ($group !== null) {
            if ($group->status === GroupStatusLabel::New) {
                $trend = ' (new)';
            } elseif ($group->status === GroupStatusLabel::Grown && $group->previousCount !== null) {
                $trend = sprintf(' (↑ from %s)', number_format($group->previousCount));
            } elseif ($group->status === GroupStatusLabel::Expected) {
                $trend = $d->comparison?->usedMode === 'weekday' ? ' (expected for this weekday)' : ' (seen in earlier windows)';
            }
        }

        $after = $group !== null ? ($d->afterReleaseFingerprints[$group->fingerprint] ?? null) : null;
        if ($after !== null) {
            $trend .= ' · first seen after ' . SlackText::escape($after);
        }

        $title = $f->title;
        foreach ($f->fingerprints as $fp) {
            if (isset($d->findingLabels[$fp])) {
                $title = $d->findingLabels[$fp] . ' — ' . $title;
                break;
            }
        }

        return sprintf(
            "• %s — %s×%s  `%s`\n  Evidence: %s\n  Hypothesis: %s  Next: %s",
            SlackText::escape($title),
            number_format($count),
            $trend,
            substr($f->fingerprints[0], 0, 12),
            SlackText::escape($f->evidence),
            SlackText::escape($f->suspectedCause),
            SlackText::escape($f->suggestedAction)
        ) . self::adviceLines($f);
    }

    /** `Check:` and `Related:` (model text, so escaped like the rest); nothing when the model gave none or analysis.advice is off. */
    private static function adviceLines(Finding $f): string
    {
        $out = $f->howToCheck === '' ? '' : "\n  Check: " . SlackText::escape($f->howToCheck);
        if ($f->relatedChecks !== []) {
            $out .= "\n  Related: " . implode(' · ', array_map(SlackText::escape(...), $f->relatedChecks));
        }

        return $out;
    }

    /**
     * Links are built by us from config and redacted data, so they stay clickable (never passed through escape());
     * the labels are fixed text and a URL that could break out of Slack's `<url|label>` syntax is dropped.
     */
    public static function linksLine(Finding $f, DigestData $d): string
    {
        $g = self::primaryGroup($f, $d);
        $set = $g === null ? null : ($d->links[$g->fingerprint] ?? null);
        if ($set === null) {
            return '';
        }
        $parts = [];
        $log = self::link($set['log'], 'log');
        if ($log !== null) {
            $parts[] = $log;
        }
        $trace = self::link($set['trace'] ?? null, 'trace');
        if ($trace !== null) {
            $parts[] = $trace;
        }
        $issue = self::link($set['sentry'] ?? null, 'Sentry');
        if ($issue !== null) {
            $parts[] = $issue;
        }
        $n = 0;
        foreach ($set['code'] as $url) {
            $code = $n < 3 ? self::link($url, 'code ' . ($n + 1)) : null;
            if ($code !== null) {
                $parts[] = $code;
                ++$n;
            }
        }
        while ($parts !== []) {
            $line = "\n  Links: " . implode(' · ', $parts);
            if (mb_strlen($line) <= self::MAX_LINKS_CHARS) {
                return $line;
            }
            array_pop($parts);
        }

        return '';
    }

    /** One extra line when the fleet linked this finding to an upstream one (spec 003); empty otherwise. */
    public static function annotationLine(Finding $f, DigestData $d): string
    {
        foreach ($f->fingerprints as $fp) {
            if (isset($d->annotations[$fp])) {
                return "\n  🔗 " . SlackText::escape($d->annotations[$fp]);
            }
        }

        return '';
    }

    /** One documentation line for the finding's endpoint (spec 010): masked and capped upstream, escaped here like any model-adjacent text. */
    public static function endpointLine(Finding $f, DigestData $d): string
    {
        foreach ($f->fingerprints as $fp) {
            if (isset($d->endpoints->lines[$fp])) {
                return "\n  📖 " . SlackText::escape($d->endpoints->lines[$fp]);
            }
        }

        return '';
    }

    private static function link(?string $url, string $label): ?string
    {
        return $url !== null && preg_match('~^https?://[^\s<>|]+$~D', $url) === 1 ? "<$url|$label>" : null;
    }

    private static function primaryGroup(Finding $f, DigestData $d): ?\Watcher\Privacy\RedactedGroup
    {
        $best = null;
        foreach ($f->fingerprints as $fp) {
            $g = $d->groups[$fp] ?? null;
            if ($g !== null && ($best === null || $g->count > $best->count)) {
                $best = $g;
            }
        }

        return $best;
    }
}
