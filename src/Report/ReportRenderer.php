<?php

declare(strict_types=1);

namespace Watcher\Report;

use Watcher\Notify\Message;

final class ReportRenderer
{
    public function render(ReportData $d): Message
    {
        $env = $d->environment === 'unset' ? '' : ' / ' . $d->environment;
        $header = sprintf(
            '📊 %s%s — week %s (Mon %s – Sun %s, UTC)',
            $d->service,
            $env,
            $d->isoWeek,
            $d->rangeStart,
            $d->rangeEnd,
        );
        $runs = sprintf(
            'Runs: %d reported/quiet · %d failed · %d baselined',
            $d->runs['reported'] + $d->runs['quiet'],
            $d->runs['failed'],
            $d->runs['baselined'],
        );
        $lines = [$header, $runs];
        if ($d->recurring !== []) {
            $lines[] = 'Recurring (days seen): ' . implode(' · ', array_map($this->recurring(...), $d->recurring));
        }
        if ($d->stopped !== []) {
            $lines[] = 'Stopped after a release (no longer among the top groups): ' . implode(' · ', array_map(
                static fn (array $r): string => $r['fingerprint'] . ' after ' . $r['release'],
                $d->stopped,
            ));
        }
        if ($d->noisiest !== []) {
            $lines[] = 'Noisiest groups (events): ' . implode(' · ', array_map(
                static fn (array $r): string => $r['fingerprint'] . ' ' . $r['level'] . ' ' . number_format($r['count']),
                $d->noisiest,
            ));
        }
        $lines[] = sprintf(
            'Model spend: %s tokens in / %s out · $%s',
            $this->compact($d->tokensIn),
            $this->compact($d->tokensOut),
            number_format($d->costUsd, 2),
        );
        $lines[] = 'Muted groups (sum over runs): ' . number_format($d->muted);
        if ($d->knownTotal > 0) {
            $lines[] = sprintf('Known issues: %d of %d entries seen this week', $d->knownSeen, $d->knownTotal);
        }
        $lines[] = sprintf('Coverage: top %d groups per run · %d lines skipped', $d->topGroupsPerRun, $d->linesSkipped);
        $text = implode("\n", $lines);

        return new Message($text, [['kind' => 'notice', 'text' => $text]]);
    }

    /** @param array{fingerprint: string, level: string, days: int, days_total: int, known: ?string} $r */
    private function recurring(array $r): string
    {
        $known = $r['known'] !== null ? ' (known: ' . $r['known'] . ')' : '';

        return $r['fingerprint'] . ' ' . $r['level'] . ' ' . $r['days'] . '/' . $r['days_total'] . ' days' . $known;
    }

    private function compact(int $n): string
    {
        if ($n >= 1_000_000) {
            return number_format($n / 1_000_000, 1) . ' M';
        }
        if ($n >= 1000) {
            return number_format($n / 1000, 0) . ' k';
        }

        return (string) $n;
    }
}
