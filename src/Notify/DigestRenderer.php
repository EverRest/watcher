<?php

declare(strict_types=1);

namespace Watcher\Notify;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Privacy\GroupStatusLabel;

/**
 * Renders the digest (contracts/slack-messages.md) into a neutral Message.
 *
 * Message::$sections kinds, in order: header, then per severity a severity heading followed by its
 * findings (kind "severity", "finding"), then optional "minor" and "notice" lines, then "footer".
 * Message::$text is a plain fallback (title line + counts), at most 4,000 characters.
 * Section text is Slack mrkdwn with every model-provided string escaped.
 */
final class DigestRenderer
{
    public const MAX_SECTIONS = 40;
    public const MAX_SECTION_CHARS = 2800;
    public const MAX_TEXT_CHARS = 4000;

    private const ICONS = ['critical' => '🔴', 'error' => '🟠', 'warn' => '🟡', 'info' => '🔵'];

    public function render(DigestData $d, float $confidenceThreshold): Message
    {
        $shown = [];
        $minor = 0;
        foreach ($d->findings as $i => $f) {
            if ($this->isMinor($f, $confidenceThreshold)) {
                ++$minor;
                continue;
            }
            $shown[] = ['f' => $f, 'n' => $this->count($f, $d), 'i' => $i];
        }
        usort($shown, fn (array $a, array $b): int => [$this->rank($b['f']->severity), $b['n'], $a['i']]
            <=> [$this->rank($a['f']->severity), $a['n'], $b['i']]);

        // Never split: overflow moves the lowest-severity findings into the minor line.
        while (count($sections = $this->sections($d, $shown, $minor)) > self::MAX_SECTIONS && $shown !== []) {
            array_pop($shown);
            ++$minor;
        }

        $header = $this->header($d);
        $counts = count($shown) . ' findings' . ($minor > 0 ? ", $minor minor" : '');

        return new Message(
            $this->cap($this->plain($header) . "\n" . $counts, self::MAX_TEXT_CHARS),
            $sections
        );
    }

    public function renderBaseline(string $service, string $environment, int $groups, int $events): Message
    {
        $text = sprintf(
            '✅ watcher · %s · %s — baseline recorded: %s groups, %s events. Analysis starts next run.',
            $this->esc($service),
            $this->esc($environment),
            number_format($groups),
            number_format($events)
        );

        return new Message($this->cap($this->plain($text), self::MAX_TEXT_CHARS), [['kind' => 'notice', 'text' => $text]]);
    }

    /**
     * @param list<array{f: Finding, n: int, i: int}> $shown
     *
     * @return list<array{kind: string, text: string}>
     */
    private function sections(DigestData $d, array $shown, int $minor): array
    {
        $out = [['kind' => 'header', 'text' => $this->header($d)]];

        $bySeverity = [];
        foreach ($shown as $s) {
            $bySeverity[$s['f']->severity->value][] = $s;
        }
        foreach ([Severity::Critical, Severity::Error, Severity::Warn, Severity::Info] as $sev) {
            $items = $bySeverity[$sev->value] ?? [];
            if ($items === []) {
                continue;
            }
            $out[] = ['kind' => 'severity', 'text' => sprintf('%s *%s* (%d)', self::ICONS[$sev->value], $sev->value, count($items))];
            foreach ($items as $s) {
                $out[] = ['kind' => 'finding', 'text' => $this->cap($this->finding($s['f'], $s['n'], $d), self::MAX_SECTION_CHARS)];
            }
        }

        $parts = [];
        if ($minor > 0) {
            $parts[] = "➕ $minor minor (low confidence / noise)";
        }
        if ($d->otherGroups > 0) {
            $parts[] = sprintf('%s more groups, %s events', number_format($d->otherGroups), number_format($d->otherEvents));
        }
        if ($d->muted > 0) {
            $parts[] = "{$d->muted} muted";
        }
        if ($parts !== []) {
            $out[] = ['kind' => 'minor', 'text' => implode(' · ', $parts)];
        }

        if ($d->levelAnomalyLine !== null) {
            $out[] = ['kind' => 'notice', 'text' => '📈 ' . $this->esc($d->levelAnomalyLine)];
        }
        if ($d->eventsReadTruncated !== null) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ truncated: %s events read', number_format($d->eventsReadTruncated))];
        }
        if ($d->droppedGroups > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ truncated: %s groups dropped (budget)', number_format($d->droppedGroups))];
        }

        $out[] = ['kind' => 'footer', 'text' => $this->footer($d)];

        return $out;
    }

    private function finding(Finding $f, int $count, DigestData $d): string
    {
        $trend = '';
        $group = $this->primaryGroup($f, $d);
        if ($group !== null) {
            if ($group->status === GroupStatusLabel::New) {
                $trend = ' (new)';
            } elseif ($group->status === GroupStatusLabel::Grown && $group->previousCount !== null) {
                $trend = sprintf(' (↑ from %s)', number_format($group->previousCount));
            }
        }

        return sprintf(
            "• %s — %s×%s  `%s`\n  Evidence: %s\n  Hypothesis: %s  Next: %s",
            $this->esc($f->title),
            number_format($count),
            $trend,
            substr($f->fingerprints[0], 0, 12),
            $this->esc($f->evidence),
            $this->esc($f->suspectedCause),
            $this->esc($f->suggestedAction)
        );
    }

    private function footer(DigestData $d): string
    {
        $u = $d->usage;
        $parts = [$u === null
            ? 'tokens n/a'
            : sprintf('tokens in %s / out %s / cached %s', $this->k($u->inputTokens), $this->k($u->outputTokens), $this->k($u->cacheReadTokens))];
        foreach ($d->models as $step => $model) {
            $parts[] = $this->esc("$step $model");
        }
        $parts[] = sprintf('≈ $%s', number_format($d->costUsd, 2));
        if ($d->todayUsd !== null && $d->dailyBudgetUsd !== null && $d->dailyBudgetUsd > 0) {
            $part = sprintf('today $%s of $%s (%d%%)', number_format($d->todayUsd, 2), number_format($d->dailyBudgetUsd, 2), round($d->todayUsd / $d->dailyBudgetUsd * 100));
            if ($d->todayTokens !== null && $d->dailyTokenLimit !== null) {
                $part .= sprintf(' · tokens today %s of %s (%d%%)', $this->k($d->todayTokens), $this->k($d->dailyTokenLimit), round($d->todayTokens / $d->dailyTokenLimit * 100));
            }
            $parts[] = $part;
        }

        return '— ' . implode(' · ', $parts);
    }

    private function header(DigestData $d): string
    {
        $tz = new DateTimeZone($d->timezone);
        $fmt = static fn (DateTimeImmutable $t): string => $t->setTimezone($tz)->format('Y-m-d H:i');

        return sprintf(
            '🔎 %s · %s · %s → %s (%s)%s',
            $this->esc($d->service),
            $this->esc($d->environment),
            $fmt($d->windowStart),
            $fmt($d->windowEnd),
            $this->esc($d->timezone),
            $d->release !== null ? ' · ' . $this->esc($d->release) : ''
        );
    }

    private function isMinor(Finding $f, float $threshold): bool
    {
        if ($f->severity === Severity::Error || $f->severity === Severity::Critical) {
            return false;
        }

        return $f->kind === FindingKind::Noise || $f->confidence < $threshold;
    }

    private function rank(Severity $s): int
    {
        return match ($s) {
            Severity::Critical => 3,
            Severity::Error => 2,
            Severity::Warn => 1,
            Severity::Info => 0,
        };
    }

    private function count(Finding $f, DigestData $d): int
    {
        $n = 0;
        foreach ($f->fingerprints as $fp) {
            $n += isset($d->groups[$fp]) ? $d->groups[$fp]->count : 0;
        }

        return $n;
    }

    private function primaryGroup(Finding $f, DigestData $d): ?\Watcher\Privacy\RedactedGroup
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

    private function k(int $n): string
    {
        return $n < 1000 ? (string) $n : rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.') . 'k';
    }

    private function esc(string $s): string
    {
        return SlackText::escape($s);
    }

    private function plain(string $s): string
    {
        return SlackText::plain($s);
    }

    private function cap(string $s, int $max): string
    {
        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 1) . '…';
    }
}
