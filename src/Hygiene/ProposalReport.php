<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/**
 * US3: mute and watch fragments, ready to paste, each with its evidence and (for a mute) what a replay of the stored
 * history shows. Applying is the owner's act; this only prints.
 *
 * @phpstan-import-type Proposal from Proposals
 */
final class ProposalReport implements Report
{
    /** @param array{history_days: int, days_needed: int, proposals: list<Proposal>} $data */
    public function __construct(private readonly array $data)
    {
    }

    public function name(): string
    {
        return 'proposals';
    }

    public function isEmpty(): bool
    {
        return $this->data['proposals'] === [];
    }

    public function toArray(): array
    {
        return ['history_days' => $this->data['history_days'], 'days_needed' => $this->data['days_needed'], 'proposals' => $this->data['proposals']];
    }

    public function text(bool $notice = false): string
    {
        if ($this->data['days_needed'] > 0) {
            return sprintf('proposals: %d more day(s) of history needed (have %d)', $this->data['days_needed'], $this->data['history_days']);
        }
        if ($this->isEmpty()) {
            return 'proposals: nothing to report';
        }
        $out = ['proposals: paste into the configuration yourself; nothing was changed'];
        foreach ($this->data['proposals'] as $p) {
            $out[] = sprintf('  %-5s %s  %s', $p['type'], $p['id'], ReportText::safe($notice, $p['fragment']));
            $e = $p['evidence'];
            if ($p['type'] === 'mute') {
                $out[] = sprintf('        seen %d/%d days, %d events/day, %d tokens', $e['days_seen'], $this->data['history_days'], $e['events_per_day'], $e['tokens']);
                if (isset($p['replay'])) {
                    $out[] = sprintf('        replay: %d candidates removed, %d tokens saved over %d days', $p['replay']['candidates_removed'], $p['replay']['tokens_saved'], $p['replay']['days']);
                }
            } else {
                $out[] = sprintf('        precedes a severe family on %d of %d days (within %d s)', $e['days_with_precursor'], $e['days_with_severe'], $e['lead_seconds']);
            }
        }
        $out[] = '  decline one for the configured period: watcher hygiene --config=... --decline=<id>';

        return implode("\n", $out);
    }
}
