<?php

declare(strict_types=1);

namespace Watcher\Notify;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Analyze\Finding;
use Watcher\Analyze\Severity;
use Watcher\Baseline\Comparison;

/** The digest's section list (contracts/slack-messages.md): header, per-severity findings, then the notices. Internal to DigestRenderer. */
final class DigestSections
{
    private const ICONS = ['critical' => '🔴', 'error' => '🟠', 'warn' => '🟡', 'info' => '🔵'];

    public static function header(DigestData $d): string
    {
        $tz = new DateTimeZone($d->timezone);
        $fmt = static fn (DateTimeImmutable $t): string => $t->setTimezone($tz)->format('Y-m-d H:i');

        return sprintf(
            '🔎 %s · %s · %s → %s (%s)%s',
            SlackText::escape($d->service),
            SlackText::escape($d->environment),
            $fmt($d->windowStart),
            $fmt($d->windowEnd),
            SlackText::escape($d->timezone),
            $d->release !== null ? ' · ' . SlackText::escape($d->release) : ''
        );
    }

    /**
     * @param list<array{f: Finding, n: int, i: int}> $shown
     *
     * @return list<array{kind: string, text: string}>
     */
    public static function build(DigestData $d, array $shown, int $minor): array
    {
        $out = [['kind' => 'header', 'text' => self::header($d)]];
        if ($d->extras?->metricsLine !== null) {
            $out[] = ['kind' => 'notice', 'text' => '📊 ' . SlackText::escape($d->extras->metricsLine)];
        }

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
                $links = FindingLines::annotationLine($s['f'], $d) . FindingLines::endpointLine($s['f'], $d) . FindingLines::linksLine($s['f'], $d);
                $out[] = ['kind' => 'finding', 'text' => SlackText::cap(FindingLines::finding($s['f'], $s['n'], $d), DigestRenderer::MAX_SECTION_CHARS - mb_strlen($links)) . $links];
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
            $out = [...$out, ...self::comparedWith($d, $d->comparedWith)];
        }
        if ($d->gapFrom !== null && $d->gapTo !== null) {
            $out[] = ['kind' => 'notice', 'text' => self::gapLine($d)];
        }
        if ($d->catchUpBatch !== null) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('ℹ️ catch-up: %d windows run, oldest first, because the host did not trigger in time.', $d->catchUpBatch)];
        }
        if ($d->levelAnomalyLine !== null) {
            $out[] = ['kind' => 'notice', 'text' => '📈 ' . SlackText::escape($d->levelAnomalyLine)];
        }
        if ($d->comparison !== null && ($line = self::comparisonLine($d, $d->comparison)) !== null) {
            $out[] = ['kind' => 'notice', 'text' => 'ℹ️ ' . $line];
        }
        if ($d->eventsReadTruncated !== null) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ truncated: %s events read', number_format($d->eventsReadTruncated))];
        }
        if ($d->droppedGroups > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ truncated: %s groups dropped (input limit)', number_format($d->droppedGroups))];
        }
        if (($line = self::readQualityLine($d->readDiagnostics)) !== null) {
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
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ %s group(s) flagged interesting were not analysed (top_m cut)', number_format($d->notAnalysed))];
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
        if ($d->escalationsSkipped > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ %s escalation(s) skipped (max_input_tokens.escalate): findings shown as first analysed', number_format($d->escalationsSkipped))];
        }
        if ($d->patternFailures > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('⚠️ %s pattern evaluation failures: rules may have been skipped', number_format($d->patternFailures))];
        }
        if ($d->routedNotice !== null && $d->routedNotice !== '') {
            $out[] = ['kind' => 'notice', 'text' => 'ℹ️ ' . SlackText::escape($d->routedNotice)];
        }
        if ($d->draftsSkipped > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('ℹ️ %s more drafts not sent', number_format($d->draftsSkipped))];
        }

        if ($d->undeclaredHostCount > 0) {
            $out[] = ['kind' => 'notice', 'text' => sprintf('🔗 %d call target(s) not declared as dependencies', $d->undeclaredHostCount)];
        }
        foreach ($d->failedDependencies as $name) {
            $out[] = ['kind' => 'notice', 'text' => '⚠️ dependency unavailable: ' . SlackText::escape($name)];
        }

        foreach ($d->extras?->notes() ?? [] as $note) {
            $out[] = ['kind' => 'notice', 'text' => '⚠️ ' . SlackText::escape($note)];
        }
        $e = $d->endpoints;
        if ($e !== null) {
            if ($e->skipped > 0) {
                $out[] = ['kind' => 'notice', 'text' => sprintf('ℹ️ %s endpoint line(s) skipped (api_context.max_endpoints)', number_format($e->skipped))];
            }
            if ($e->droppedOps > 0) {
                $out[] = ['kind' => 'notice', 'text' => sprintf('ℹ️ %s operation(s) not kept (cache limit)', number_format($e->droppedOps))];
            }
            if ($e->modelDropped) {
                $out[] = ['kind' => 'notice', 'text' => 'ℹ️ endpoint documentation not sent to the model (input limit)'];
            }
            if ($e->unavailable !== null) {
                $out[] = ['kind' => 'notice', 'text' => '⚠️ API context unavailable (' . SlackText::escape($e->unavailable) . ')'];
            }
        }

        return $out;
    }

    /** @param array{unknown_levels?: int, orphan_trace_lines?: int, message_tails_dropped?: int, truncated_lines?: int, regex_failures?: int, truncated_structured?: int, pages_shrunk?: int, malformed_entries?: int, oversized_lines?: int, unscannable_events?: int} $diag */
    private static function readQualityLine(array $diag): ?string
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
            'malformed_entries' => '%s unusable entries from the source skipped',
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
    private static function comparedWith(DigestData $d, DateTimeImmutable $with): array
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
    private static function comparisonLine(DigestData $d, Comparison $c): ?string
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

        return $parts === [] ? null : SlackText::escape(implode(' ', $parts));
    }

    private static function gapLine(DigestData $d): string
    {
        assert($d->gapFrom !== null && $d->gapTo !== null);

        return DigestRenderer::formatGapLine($d->gapFrom, $d->gapTo, $d->gapWindows);
    }
}
