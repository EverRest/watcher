<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use DateTimeImmutable;
use Watcher\Level;

/**
 * US3: mute and watch rules the stored history justifies, as text for the owner to paste (research R7). Nothing here
 * changes the configuration. Mute: stable, high-volume and never at or above the candidate level; a family that was ever
 * severe within the history, or whose template was severe under another fingerprint, is never proposed (FR-011).
 *
 * @phpstan-type Proposal array{id: string, type: string, status: string, fragment: string, evidence: array<string, int>, expected_saving?: array<string, int|float>, replay?: array{days: int, candidates_removed: int}}
 */
final class Proposals
{
    /** A mute needs the family on at least this share of the history days. */
    private const PRESENT = 0.8;

    /** A mute needs max daily count at most this many times the min daily count. */
    private const STABLE = 3;

    /** A watch rule needs the coincidence on at least this many days. */
    private const WATCH_DAYS = 3;

    /** A watch rule needs at least this many literal characters (placeholders and spaces do not count). */
    private const MIN_LITERAL = 8;

    /**
     * @param list<array<string, mixed>> $mute `mute` of the configuration, to skip what is already applied
     * @param array<string, array<string, mixed>> $watchRules `watch_rules` of the configuration
     */
    public function __construct(
        private readonly HygieneSettings $settings,
        private readonly Level $severe,
        private readonly array $mute,
        private readonly array $watchRules,
        private readonly HygieneHistory $history,
    ) {
    }

    /**
     * @param array<string, DayRecord> $days newest first, as {@see HygieneHistory::days()}
     * @return array{history_days: int, days_needed: int, proposals: list<Proposal>}
     */
    public function find(array $days, DateTimeImmutable $now): array
    {
        // A day cut at the event cap missed whatever came after the cut, severe families included: it is missing history, not evidence (review M2).
        $days = array_filter($days, static fn (DayRecord $d): bool => !$d->sampled);
        $h = count($days);
        if ($h < $this->settings->minHistoryDays) {
            return ['history_days' => $h, 'days_needed' => $this->settings->minHistoryDays - $h, 'proposals' => []];
        }

        return ['history_days' => $h, 'days_needed' => 0, 'proposals' => [...$this->mutes($days, $now), ...$this->watches($days, $now)]];
    }

    /**
     * Watch: a template that, on at least three days and at least half of both its own days and the days with a severe family,
     * first appeared up to `watch_lead` seconds before a severe family did.
     *
     * @param array<string, DayRecord> $days
     * @return list<Proposal>
     */
    private function watches(array $days, DateTimeImmutable $now): array
    {
        $severeDays = 0;
        /** @var array<string, array{fp: string, seen: int, prec: int}> $stat */
        $stat = [];
        foreach ($days as $day) {
            $severeStarts = [];
            $others = [];
            foreach ($day->families as $f) {
                if (Level::parse($f['level'])->atLeast($this->severe)) {
                    $severeStarts[] = (new DateTimeImmutable($f['first']))->getTimestamp();
                } elseif ($f['tpl'] !== null) {
                    $others[] = $f;
                }
            }
            $severeDays += $severeStarts === [] ? 0 : 1;
            foreach ($others as $f) {
                $t = (new DateTimeImmutable($f['first']))->getTimestamp();
                $precedes = false;
                foreach ($severeStarts as $s) {
                    $precedes = $precedes || ($s >= $t && $s - $t <= $this->settings->watchLead);
                }
                $tpl = (string) $f['tpl'];
                $stat[$tpl] ??= ['fp' => $f['fp'], 'seen' => 0, 'prec' => 0];
                ++$stat[$tpl]['seen'];
                $stat[$tpl]['prec'] += $precedes ? 1 : 0;
            }
        }
        $configured = array_filter(array_map(static fn (array $r): ?string => isset($r['template']) ? (string) $r['template'] : null, $this->watchRules));

        $out = [];
        foreach ($stat as $tpl => $s) {
            if (strlen((string) preg_replace('/<(?:[A-Z*]+(?::[^>]*)?|w)>|\s+/', '', (string) $tpl)) < self::MIN_LITERAL) {
                continue; // `/.*/` would match every line
            }
            $regex = self::regexOf((string) $tpl);
            $id = substr(hash('sha256', 'watch|' . $tpl), 0, 10);
            if ($s['prec'] < self::WATCH_DAYS || $s['prec'] < 0.5 * $severeDays || $s['prec'] < 0.5 * $s['seen']
                || in_array($regex, $configured, true) || $this->history->isDeclined($id, $now)) {
                continue;
            }
            // Invisible format characters (zero-width space, bidi marks) from the log must not end up in a configuration paste.
            $literal = ReportText::clean(str_replace(['\\\\', "'"], ['\\\\\\\\', "\\'"], $regex));
            $out[] = [
                'id' => $id,
                'type' => 'watch',
                'status' => 'open',
                'fragment' => "'precursor-" . substr($s['fp'], 0, 6) . "' => ['template' => '$literal'],",
                'evidence' => ['days_with_precursor' => $s['prec'], 'days_with_severe' => $severeDays, 'lead_seconds' => $this->settings->watchLead],
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['evidence']['days_with_precursor'], $a['id']] <=> [$a['evidence']['days_with_precursor'], $b['id']]);

        return $out;
    }

    /** The rule that matches a masked template: its literal parts quoted, each placeholder (`<N>`, `<*>`) any text. */
    private static function regexOf(string $template): string
    {
        $parts = preg_split('/<(?:[A-Z*]+(?::[^>]*)?|w)>/', $template) ?: [$template]; // `<w>` is the word floor's placeholder: a wildcard here

        return '/' . implode('.*', array_map(static fn (string $p): string => preg_quote($p, '/'), $parts)) . '/';
    }

    /**
     * @param array<string, DayRecord> $days
     * @return list<Proposal>
     */
    private function mutes(array $days, DateTimeImmutable $now): array
    {
        $h = count($days);
        /** @var array<string, list<array{n: int, severe: bool, tpl: ?string}>> $rows */
        $rows = [];
        $severeTemplates = [];
        foreach ($days as $day) {
            foreach ($day->families as $f) {
                $severe = Level::parse($f['level'])->atLeast($this->severe);
                $rows[$f['fp']][] = ['n' => $f['n'], 'severe' => $severe, 'tpl' => $f['th'] ?? $f['tpl']];
                $templateKey = $f['th'] ?? $f['tpl']; // the hash covers a constant message whose text is withheld
                if ($severe && $templateKey !== null) {
                    $severeTemplates[$templateKey] = true;
                }
            }
        }
        $muted = [];
        foreach ($this->mute as $entry) {
            if (isset($entry['fingerprint'])) {
                $muted[(string) $entry['fingerprint']] = true;
            }
        }

        $out = [];
        foreach ($rows as $fp => $r) {
            $fp = (string) $fp;
            $counts = array_column($r, 'n');
            if ($counts === []) {
                continue;
            }
            $total = array_sum($counts);
            $tpl = array_values(array_filter(array_column($r, 'tpl'), static fn (?string $t): bool => $t !== null))[0] ?? null;
            if (in_array(true, array_column($r, 'severe'), true) || ($tpl !== null && isset($severeTemplates[$tpl]))
                || count($r) / $h < self::PRESENT || $total / $h < $this->settings->muteMinDaily
                || max($counts) > self::STABLE * min($counts) || isset($muted[$fp])) {
                continue;
            }
            $id = substr(hash('sha256', 'mute|' . $fp), 0, 10);
            if ($this->history->isDeclined($id, $now)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'type' => 'mute',
                'status' => 'open',
                'fragment' => "['fingerprint' => '$fp', 'until' => null],",
                'evidence' => ['days_seen' => count($r), 'events_per_day' => (int) round($total / $h)],
                'expected_saving' => ['events_per_day' => (int) round($total / $h)],
                'replay' => Replay::mute($fp, $days, $now),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['evidence']['events_per_day'], $a['fragment']] <=> [$a['evidence']['events_per_day'], $b['fragment']]);

        return $out;
    }
}
