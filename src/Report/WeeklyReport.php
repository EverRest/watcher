<?php

declare(strict_types=1);

namespace Watcher\Report;

use Watcher\Incident\KnownIssue;

final class WeeklyReport
{
    /**
     * @param list<KnownIssue> $known
     */
    public function build(EventLogSnapshot $log, Week $week, string $service, string $environment, array $known, int $top, int $topGroupsPerRun): ?ReportData
    {
        if ($log->runs === []) {
            return null;
        }
        $counts = ['reported' => 0, 'quiet' => 0, 'baselined' => 0, 'failed' => 0];
        $tokensIn = $tokensOut = $muted = 0;
        $cost = 0.0;
        $releases = [];
        foreach ($log->runs as $end => $run) {
            $o = (string) ($run['outcome'] ?? '');
            if (isset($counts[$o])) {
                ++$counts[$o];
            }
            $tokensIn += (int) ($run['tokens_in'] ?? 0);
            $tokensOut += (int) ($run['tokens_out'] ?? 0);
            $muted += (int) ($run['muted'] ?? 0);
            $cost += (float) ($run['cost_usd'] ?? 0);
            if (is_string($run['release'] ?? null) && $run['release'] !== '') {
                $releases[$end] = $run['release'];
            }
        }

        $daysInWeek = $this->weekDays($week);
        $byFp = [];
        $noise = [];
        foreach ($log->groups as $g) {
            $fp = (string) $g['fingerprint'];
            $day = substr((string) $g['window_end'], 0, 10);
            $byFp[$fp]['level'] = (string) $g['level'];
            $byFp[$fp]['days'][$day] = true;
            $byFp[$fp]['count'] = ($byFp[$fp]['count'] ?? 0) + (int) $g['count'];
            $byFp[$fp]['windows'][$g['window_end']] = true;
            $noise[$fp] = ($noise[$fp] ?? 0) + (int) $g['count'];
        }

        $recurring = [];
        foreach ($byFp as $fp => $meta) {
            $days = count($meta['days']);
            if ($days < 2) {
                continue;
            }
            $recurring[] = [
                'fingerprint' => $fp,
                'level' => $meta['level'],
                'days' => $days,
                'days_total' => count($daysInWeek),
                'known' => $this->knownTicket($fp, $known),
            ];
        }
        usort($recurring, static fn (array $a, array $b): int => $b['days'] <=> $a['days'] ?: strcmp($a['fingerprint'], $b['fingerprint']));
        $recurring = array_slice($recurring, 0, $top);

        $windows = array_keys($log->runs);
        sort($windows);
        $stopped = [];
        foreach ($byFp as $fp => $meta) {
            $appeared = array_keys($meta['windows']);
            sort($appeared);
            for ($i = 1, $c = count($windows); $i < $c; ++$i) {
                $w = $windows[$i];
                $prevW = $windows[$i - 1];
                $rel = $releases[$w] ?? null;
                $prevRel = $releases[$prevW] ?? null;
                if ($rel === null || $prevRel === null || $rel === $prevRel) {
                    continue;
                }
                if (!in_array($prevW, $appeared, true)) {
                    continue;
                }
                $missing = 0;
                for ($j = $i; $j < $c && $missing < 2; ++$j) {
                    if (!isset($meta['windows'][$windows[$j]])) {
                        ++$missing;
                    }
                }
                if ($missing >= 2) {
                    $stopped[] = ['fingerprint' => $fp, 'release' => $rel];
                    break;
                }
            }
        }
        usort($stopped, static fn (array $a, array $b): int => strcmp($a['fingerprint'], $b['fingerprint']));
        $stopped = array_slice($stopped, 0, $top);

        $noisiest = [];
        foreach ($noise as $fp => $sum) {
            $noisiest[] = ['fingerprint' => $fp, 'level' => $byFp[$fp]['level'] ?? 'error', 'count' => $sum];
        }
        usort($noisiest, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['fingerprint'], $b['fingerprint']));
        $noisiest = array_slice($noisiest, 0, $top);

        [$start, $end] = $week->labelDates();
        $knownSeen = 0;
        foreach ($known as $entry) {
            foreach (array_keys($byFp) as $fp) {
                if ($entry->matches($fp)) {
                    ++$knownSeen;
                    break;
                }
            }
        }

        return new ReportData(
            $service,
            $environment,
            $week->iso,
            $start,
            $end,
            $counts,
            $recurring,
            $stopped,
            $noisiest,
            $tokensIn,
            $tokensOut,
            $cost,
            $muted,
            min($knownSeen, count($known)),
            count($known),
            $topGroupsPerRun,
            $log->skippedTotal(),
        );
    }

    /** @return list<string> Y-m-d */
    private function weekDays(Week $week): array
    {
        $out = [];
        for ($i = 0; $i < 7; ++$i) {
            $out[] = $week->start->modify('+' . $i . ' days')->format('Y-m-d');
        }

        return $out;
    }

    /** @param list<KnownIssue> $known */
    private function knownTicket(string $fp, array $known): ?string
    {
        foreach ($known as $entry) {
            if ($entry->matches($fp)) {
                return $entry->ticket;
            }
        }

        return null;
    }
}
