<?php

declare(strict_types=1);

namespace Watcher\Hygiene\Cost;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\UnknownModelPrice;
use Watcher\Analyze\Usage;
use Watcher\Privacy\RedactedGroup;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * Calls, tokens and cost per service-local day, by step, plus a bounded table of the families that consumed them (US4).
 * The run record holds the day's totals; this holds what it cannot: calls per step and who spent. A request's tokens are
 * split evenly over its groups, so a family's figure is an estimate (research R9). Numbers, fingerprints and templates
 * that were already sent to the model: nothing else.
 *
 * @phpstan-type Day array{calls: array{triage: int, analyze: int, escalate: int}, in: int, out: int, cached: int, usd: float, families: array<string, array{tpl: ?string, tokens: int, runs: int}>, other_tokens: int}
 */
final class CostLedger
{
    public const STEPS = ['triage', 'analyze', 'escalate'];

    public function __construct(
        private readonly StateStore $store,
        private readonly string $service,
        private readonly int $retention,
        private readonly DateTimeZone $timezone,
        private readonly ClockInterface $clock,
        private readonly CostCalculator $calculator,
        private readonly int $maxFamilies = 200,
    ) {
    }

    /**
     * @param 'triage'|'analyze'|'escalate' $step
     * @param list<RedactedGroup> $groups what the request carried
     */
    public function record(string $step, Usage $usage, array $groups): void
    {
        $date = $this->clock->now()->setTimezone($this->timezone)->format('Y-m-d');
        $day = $this->day($date) ?? ['calls' => ['triage' => 0, 'analyze' => 0, 'escalate' => 0], 'in' => 0, 'out' => 0, 'cached' => 0, 'usd' => 0.0, 'families' => [], 'other_tokens' => 0];
        ++$day['calls'][$step];
        $day['in'] += $usage->inputTokens;
        $day['out'] += $usage->outputTokens;
        $day['cached'] += $usage->cacheReadTokens;
        try {
            $day['usd'] += $this->calculator->cost($usage);
        } catch (UnknownModelPrice) {
            // booked at 0, as the run books it
        }

        $total = $usage->inputTokens + $usage->outputTokens + $usage->cacheReadTokens + $usage->cacheWriteTokens;
        $n = count($groups);
        foreach ($groups as $i => $g) {
            $share = intdiv($total, $n) + ($i < $total % $n ? 1 : 0);
            $f = $day['families'][$g->fingerprint] ?? ['tpl' => $g->template, 'tokens' => 0, 'runs' => 0];
            $f['tokens'] += $share;
            ++$f['runs'];
            $day['families'][$g->fingerprint] = $f;
        }
        uasort($day['families'], static fn (array $a, array $b): int => $b['tokens'] <=> $a['tokens']);
        while (count($day['families']) > $this->maxFamilies) {
            $last = array_key_last($day['families']);
            $day['other_tokens'] += $day['families'][$last]['tokens'];
            unset($day['families'][$last]);
        }

        $this->store->put(Keys::ledger($this->service, $date), $day, $this->retention);
    }

    /** A billed call could not be booked: leave a count under its own key, so the cost report can say the day is understated. */
    public function noteUnbooked(): void
    {
        $date = $this->clock->now()->setTimezone($this->timezone)->format('Y-m-d');
        $this->store->increment(Keys::ledgerUnbooked($this->service, $date), 1.0, $this->retention);
    }

    public function unbooked(string $date): int
    {
        $r = $this->store->get(Keys::ledgerUnbooked($this->service, $date));

        return is_numeric($r['total'] ?? null) ? (int) $r['total'] : 0;
    }

    /** @return Day|null null when the day has no (valid) ledger */
    public function day(string $date): ?array
    {
        $r = $this->store->get(Keys::ledger($this->service, $date));
        if ($r === null) {
            return null;
        }
        $calls = $r['calls'] ?? null;
        if (!is_array($calls) || !is_int($calls['triage'] ?? null) || !is_int($calls['analyze'] ?? null) || !is_int($calls['escalate'] ?? null)
            || !is_int($r['in'] ?? null) || !is_int($r['out'] ?? null) || !is_int($r['cached'] ?? null)
            || !(is_float($r['usd'] ?? null) || is_int($r['usd'] ?? null)) || !is_int($r['other_tokens'] ?? null) || !is_array($r['families'] ?? null)) {
            return null;
        }
        $families = [];
        foreach ($r['families'] as $fp => $f) {
            if (!is_array($f) || !array_key_exists('tpl', $f) || !(is_string($f['tpl']) || $f['tpl'] === null) || !is_int($f['tokens'] ?? null) || !is_int($f['runs'] ?? null)) {
                return null;
            }
            $families[(string) $fp] = ['tpl' => $f['tpl'], 'tokens' => $f['tokens'], 'runs' => $f['runs']];
        }

        return [
            'calls' => ['triage' => $calls['triage'], 'analyze' => $calls['analyze'], 'escalate' => $calls['escalate']],
            'in' => $r['in'], 'out' => $r['out'], 'cached' => $r['cached'], 'usd' => (float) $r['usd'],
            'families' => $families, 'other_tokens' => $r['other_tokens'],
        ];
    }
}
