<?php

declare(strict_types=1);

namespace Watcher\Hygiene\Cost;

use Watcher\Hygiene\Report;
use Watcher\Hygiene\ReportText;

/**
 * US4: tokens and cost per service and day, spikes with their likely causes, top spenders by fingerprint and masked template.
 * Wraps what {@see CostReader::read()} returns; every figure is a number, a date, a fingerprint or an allowed template.
 *
 * @phpstan-import-type DayRow from CostReader
 */
final class CostReport implements Report
{
    /**
     * @param array{days: list<DayRow>, total: array{runs: int, tokens: int, cost_usd: float, unbooked: int}, top_spenders: list<array{fingerprint: string, template: ?string, tokens: int, runs: int}>, median_cost_usd: float, ledger: bool} $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function name(): string
    {
        return 'cost';
    }

    public function isEmpty(): bool
    {
        return $this->data['total']['runs'] === 0;
    }

    public function toArray(): array
    {
        return [
            'days' => $this->data['days'],
            'total' => $this->data['total'],
            'top_spenders' => $this->data['top_spenders'],
            'median_cost_usd' => $this->data['median_cost_usd'],
            'ledger' => $this->data['ledger'],
        ];
    }

    public function text(bool $notice = false): string
    {
        if ($this->isEmpty()) {
            return 'cost: nothing to report';
        }
        $out = ['cost: model spend per day'];
        foreach ($this->data['days'] as $d) {
            $line = sprintf('  %s  %d run(s)  %d tokens (in %d, out %d, cached %d)  $%s', $d['date'], $d['runs'], $d['tokens'], $d['tokens_in'], $d['tokens_out'], $d['tokens_cached'], number_format($d['cost_usd'], 4, '.', ''));
            $line .= sprintf('  %s%% of the cap', rtrim(rtrim(number_format($d['cap_share']['usd'] * 100, 1, '.', ''), '0'), '.'));
            if ($d['calls'] !== null) {
                $line .= sprintf('  calls: %d triage, %d analyze, %d escalate', $d['calls']['triage'], $d['calls']['analyze'], $d['calls']['escalate']);
            }
            if ($d['unbooked'] > 0) {
                $line .= sprintf('  %d call(s) not booked in the ledger (the per-step numbers are understated)', $d['unbooked']);
            }
            if ($d['flagged']) {
                $causes = implode(', ', array_map(static fn (array $c): string => $c['count'] . ' ' . $c['cause'], $d['causes']));
                $line .= '  SPIKE' . ($causes === '' ? '' : ' (likely: ' . $causes . ')');
            }
            $out[] = $line;
        }
        $t = $this->data['total'];
        $out[] = sprintf('  total: %d runs, %d tokens, $%s', $t['runs'], $t['tokens'], number_format($t['cost_usd'], 4, '.', ''));
        if (!$this->data['ledger']) {
            $out[] = '  calls per step and top spenders are not recorded (set hygiene.enabled=true to keep the ledger)';
        } elseif ($this->data['top_spenders'] !== []) {
            $out[] = '  top spenders (estimated: tokens are split over the groups of a request):';
            foreach ($this->data['top_spenders'] as $s) {
                $out[] = sprintf('    %s  %d tokens in %d call(s)  %s', $s['fingerprint'], $s['tokens'], $s['runs'], ReportText::safe($notice, $s['template'] ?? '[template withheld]'));
            }
        }

        return implode("\n", $out);
    }
}
