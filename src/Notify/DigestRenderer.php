<?php

declare(strict_types=1);

namespace Watcher\Notify;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Baseline\Comparison;
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
    private const MAX_LINKS_CHARS = 1800;

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
                $links = $this->annotationLine($s['f'], $d) . $this->linksLine($s['f'], $d);
                $out[] = ['kind' => 'finding', 'text' => $this->cap($this->finding($s['f'], $s['n'], $d), self::MAX_SECTION_CHARS - mb_strlen($links)) . $links];
            }
        }

        if ($d->knownLine !== null && $d->knownLine !== '') {
            $out[] = ['kind' => 'minor', 'text' => $d->knownLine];
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

        foreach ($d->mutePartial as $fp => [$matched, $events, $index]) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('ℹ️ `%s` kept: %d of %d events match mute #%d', substr((string) $fp, 0, 12), $matched, $events, $index)];
        }

        if ($d->comparedWith !== null) {
            $out = [...$out, ...$this->comparedWith($d, $d->comparedWith)];
        }
        if ($d->levelAnomalyLine !== null) {
            $out[] = ['kind' => 'notice', 'text' => '📈 ' . $this->esc($d->levelAnomalyLine)];
        }
        if ($d->comparison !== null && ($line = $this->comparisonLine($d, $d->comparison)) !== null) {
            $out[] = ['kind' => 'notice', 'text' => 'ℹ️ ' . $line];
        }
        if ($d->eventsReadTruncated !== null) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ truncated: %s events read', number_format($d->eventsReadTruncated))];
        }
        if ($d->droppedGroups > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ truncated: %s groups dropped (budget)', number_format($d->droppedGroups))];
        }
        if (($line = $this->readQualityLine($d->readDiagnostics)) !== null) {
            $out[] = ['kind' => 'notice', 'text' => $line];
        }

        if ($d->overflowedEvents > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ cluster cap hit: %s events from distinct messages beyond the first %s were grouped as other', number_format($d->overflowedEvents), number_format($d->groupCap))];
        }

        if ($d->mining !== null && $d->mining->droppedMessages > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ Grouping limit reached: %s messages were not clustered and their groups show no message text.', number_format($d->mining->droppedMessages))];
        }
        if ($d->mining !== null && $d->mining->budgetExhausted) {
            $out[] = ['kind' => 'notice', 'text' => '⚠️ Grouping time budget reached: some groups show no message text.'];
        }

        if ($d->notAnalysed > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ %s group(s) flagged interesting were not analysed (budget exhausted, triage only or top_m cut)', number_format($d->notAnalysed))];
        }
        if ($d->triageCoerced > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ triage marked %s error-or-higher or watch-rule group(s) as noise, known or left them out; they were analysed anyway', number_format($d->triageCoerced))];
        }
        if ($d->unreported > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ %s error-or-higher or watch-rule group(s) were analysed but the model reported nothing for them; they are analysed again next window', number_format($d->unreported))];
        }
        if ($d->newOverflow > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ %s new group(s) not analysed: new groups are capped at half of top_m per run', number_format($d->newOverflow))];
        }
        if ($d->triageDropped > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('ℹ️ %s lower-level group(s) dropped by triage as noise or known', number_format($d->triageDropped))];
        }
        if ($d->reusedGroups > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('ℹ️ %s group(s) reused from earlier verdicts (not analysed again)', number_format($d->reusedGroups))];
        }
        if ($d->notTriaged > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ %s group(s) not triaged: the daily budget would have been exceeded', number_format($d->notTriaged))];
        }
        if ($d->escalationsSkipped > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ %s escalation(s) skipped (daily budget or max_input_tokens.escalate): findings shown as first analysed', number_format($d->escalationsSkipped))];
        }
        if ($d->patternFailures > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ %s pattern evaluation failures: rules may have been skipped', number_format($d->patternFailures))];
        }
        if ($d->routedNotice !== null && $d->routedNotice !== '') {
            $out[] = ['kind' => 'notice', 'text' => 'ℹ️ ' . $this->esc($d->routedNotice)];
        }
        if ($d->draftsSkipped > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('ℹ️ %s more drafts not sent', number_format($d->draftsSkipped))];
        }

        if ($d->undeclaredHostCount > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('🔗 %d call target(s) not declared as dependencies', $d->undeclaredHostCount)];
        }
        foreach ($d->failedDependencies as $name) {
            $out[] = ['kind' => 'notice', 'text' => '⚠️ dependency unavailable: ' . $this->esc($name)];
        }

        $out[] = ['kind' => 'footer', 'text' => $this->footer($d)];

        return $out;
    }

    /** @param array{unknown_levels?: int, orphan_trace_lines?: int, message_tails_dropped?: int, truncated_lines?: int, regex_failures?: int, truncated_structured?: int, pages_shrunk?: int, oversized_lines?: int, unscannable_events?: int} $diag */
    private function readQualityLine(array $diag): ?string
    {
        $parts = [];
        foreach ([
            'unknown_levels' => '%s events with an unrecognised level (read as info)',
            'orphan_trace_lines' => '%s stack-frame lines dropped (no open record)',
            'message_tails_dropped' => '%s multi-line messages cut to their first line',
            'truncated_lines' => '%s over-long lines cut before parsing',
            'truncated_structured' => '%s oversized structured lines replaced by a placeholder',
            'oversized_lines' => '%s lines over 64 KB dropped',
            'unscannable_events' => '%s events withheld (could not be scanned)',
            'pages_shrunk' => '%s pages re-read at a smaller size (response cap)',
            'regex_failures' => '%s lines the parser could not read (kept, level guessed)',
        ] as $key => $format) {
            if (($diag[$key] ?? 0) > 0) {
                $parts[] = sprintf($format, number_format($diag[$key]));
            }
        }

        return $parts === [] ? null : 'ℹ️ read quality: ' . implode(', ', $parts);
    }

    /**
     * H6: the previous window had no snapshot, so the comparison base is an older run. Counts are per window and are not scaled for the gap.
     *
     * @return list<array{kind: string, text: string}>
     */
    private function comparedWith(DigestData $d, DateTimeImmutable $with): array
    {
        $seconds = $d->windowEnd->getTimestamp() - $with->getTimestamp();
        $days = (int) round($seconds / 86400);
        $ago = $days >= 1 ? sprintf('%d day%s ago', $days, $days === 1 ? '' : 's') : sprintf('%d hour%s ago', max(1, (int) round($seconds / 3600)), round($seconds / 3600) <= 1 ? '' : 's');
        $out = [['kind' => 'notice', 'text' => sprintf('ℹ️ compared with the run of %s, %s', $with->setTimezone(new DateTimeZone($d->timezone))->format('Y-m-d'), $ago)]];
        if ($days > 1) {
            $out[] = ['kind' => 'notice', 'text' => '⚠️ no snapshot for the previous window (skipped or failed run): growth is measured against the older run, counts are per window and not scaled for the gap'];
        }

        return $out;
    }

    /** Fixed words, integers and UTC times only (contracts/digest-wording.md); nothing from a log message. */
    private function comparisonLine(DigestData $d, Comparison $c): ?string
    {
        $utc = new DateTimeZone('UTC');
        $at = static fn (DateTimeImmutable $t): string => $t->setTimezone($utc)->format('Y-m-d H:i') . ' UTC';
        $weekday = $d->windowEnd->setTimezone(new DateTimeZone($d->timezone))->format('l');
        $parts = [];

        if ($c->reason === 'short_history') {
            $parts[] = sprintf(
                '%s comparison needs %d earlier %s, found %d; compared with the previous window instead.',
                ucfirst($c->requestedMode),
                $c->requestedMode === 'weekday' ? 3 : 2,
                $c->requestedMode === 'weekday' ? $weekday . 's' : 'windows',
                $c->found,
            );
        } elseif ($c->usedMode !== 'previous' && $c->ends !== []) {
            $n = count($c->ends);
            $span = sprintf('(%s to %s)', $at($c->ends[0]), $at($c->ends[$n - 1]));
            $partial = $n < $c->candidates;
            $parts[] = $c->usedMode === 'weekday'
                ? sprintf('Compared with the median of %sthe last %s%ss %s.', $partial ? "$n of " : '', $partial ? $c->candidates . ' ' : "$n ", $weekday, $span)
                : sprintf('Compared with the average of %s%s windows %s.', $partial ? "$n of " : '', $partial ? (string) $c->candidates : (string) $n, $span);
        }
        if ($c->lengthChange !== null) {
            $parts[] = sprintf('Counts are compared per hour (window length was %s h, now %s h).', round($c->lengthChange[0], 1), round($c->lengthChange[1], 1));
        }
        if ($c->excluded > 0 && $c->usedMode !== 'previous') {
            $parts[] = sprintf('%d excluded by baseline.exclude.', $c->excluded);
        }

        return $parts === [] ? null : $this->esc(implode(' ', $parts));
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
            } elseif ($group->status === GroupStatusLabel::Expected) {
                $trend = $d->comparison?->usedMode === 'weekday' ? ' (expected for this weekday)' : ' (seen in earlier windows)';
            }
        }

        $after = $group !== null ? ($d->afterReleaseFingerprints[$group->fingerprint] ?? null) : null;
        if ($after !== null) {
            $trend .= ' · first seen after ' . $this->esc($after);
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
            $this->esc($title),
            number_format($count),
            $trend,
            substr($f->fingerprints[0], 0, 12),
            $this->esc($f->evidence),
            $this->esc($f->suspectedCause),
            $this->esc($f->suggestedAction)
        ) . $this->adviceLines($f);
    }

    /** `Check:` and `Related:` (model text, so escaped like the rest); nothing when the model gave none or analysis.advice is off. */
    private function adviceLines(Finding $f): string
    {
        $out = $f->howToCheck === '' ? '' : "\n  Check: " . $this->esc($f->howToCheck);
        if ($f->relatedChecks !== []) {
            $out .= "\n  Related: " . implode(' · ', array_map($this->esc(...), $f->relatedChecks));
        }

        return $out;
    }

    /**
     * Links are built by us from config and redacted data, so they stay clickable (never passed through escape());
     * the labels are fixed text and a URL that could break out of Slack's `<url|label>` syntax is dropped.
     */
    private function linksLine(Finding $f, DigestData $d): string
    {
        $g = $this->primaryGroup($f, $d);
        $set = $g === null ? null : ($d->links[$g->fingerprint] ?? null);
        if ($set === null) {
            return '';
        }
        $parts = [];
        $log = $this->link($set['log'], 'log');
        if ($log !== null) {
            $parts[] = $log;
        }
        $n = 0;
        foreach ($set['code'] as $url) {
            $code = $n < 3 ? $this->link($url, 'code ' . ($n + 1)) : null;
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
    private function annotationLine(Finding $f, DigestData $d): string
    {
        foreach ($f->fingerprints as $fp) {
            if (isset($d->annotations[$fp])) {
                return "\n  🔗 " . $this->esc($d->annotations[$fp]);
            }
        }

        return '';
    }

    private function link(?string $url, string $label): ?string
    {
        return $url !== null && preg_match('~^https?://[^\s<>|]+$~D', $url) === 1 ? "<$url|$label>" : null;
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
