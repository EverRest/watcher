<?php

declare(strict_types=1);

namespace Watcher\Hygiene\Cost;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Config\WatchConfig;
use Watcher\Schedule\RunGate;
use Watcher\State\Keys;
use Watcher\State\StateStore;
use Watcher\Watcher;

/**
 * What the model cost per service-local day (US4). Tokens and cost come from the **run records** (the truth the daily run
 * wrote, kept for `state.retention`), so the total is by construction the sum of recorded runs (SC-006); calls per step and the
 * top spenders come from the {@see CostLedger} when `hygiene.enabled` had it written. The two-day spend counters are not used.
 *
 * @phpstan-type DayRow array{date: string, runs: int, tokens_in: int, tokens_out: int, tokens_cached: int, tokens: int, cost_usd: float, calls: ?array{triage: int, analyze: int, escalate: int}, cap_share: array{usd: float, tokens: ?float}, flagged: bool, causes: list<array{cause: string, count: int}>, unbooked: int}
 */
final class CostReader
{
    private const TOP = 10;

    public function __construct(
        private readonly WatchConfig $config,
        private readonly StateStore $store,
        private readonly CostLedger $ledger,
    ) {
    }

    /**
     * @return array{days: list<DayRow>, total: array{runs: int, tokens: int, cost_usd: float, unbooked: int}, top_spenders: list<array{fingerprint: string, template: ?string, tokens: int, runs: int}>, median_cost_usd: float, ledger: bool}
     */
    public function read(DateTimeImmutable $now, int $days): array
    {
        $c = $this->config;
        $tz = new DateTimeZone($c->schedule['timezone']);
        $gate = RunGate::fromSchedule($c->schedule, $tz);
        $latest = $gate->latest($now)->end;

        $rows = [];
        $families = [];
        $anyLedger = false;
        for ($k = $days - 1; $k >= 0; --$k) {
            $end = $gate->endBack($latest, $k);
            $run = $this->store->get(Keys::run($c->stateKey, Watcher::windowKey($end)));
            $u = is_array($run['usage'] ?? null) ? $run['usage'] : [];
            [$in, $out, $read, $write] = [self::int($u, 'inputTokens'), self::int($u, 'outputTokens'), self::int($u, 'cacheReadTokens'), self::int($u, 'cacheWriteTokens')];
            $cost = is_numeric($u['costUsd'] ?? null) ? (float) $u['costUsd'] : 0.0;
            $date = $end->setTimezone($tz)->format('Y-m-d');
            $ledger = $this->ledger->day($date);
            if ($ledger !== null) {
                $anyLedger = true;
                foreach ($ledger['families'] as $fp => $f) {
                    $agg = $families[$fp] ?? ['tpl' => $f['tpl'], 'tokens' => 0, 'runs' => 0];
                    $agg['tokens'] += $f['tokens'];
                    $agg['runs'] += $f['runs'];
                    $families[$fp] = $agg;
                }
            }
            $tokens = $in + $out + $read + $write;
            $limit = $c->analysis['daily_token_limit'] ?? null;
            $rows[] = [
                'k' => $k,
                'row' => [
                    'date' => $date,
                    'runs' => $run === null ? 0 : 1,
                    'tokens_in' => $in,
                    'tokens_out' => $out,
                    'tokens_cached' => $read,
                    'tokens' => $tokens,
                    'cost_usd' => $cost,
                    'calls' => $ledger['calls'] ?? null,
                    'cap_share' => [
                        'usd' => round($cost / (float) $c->analysis['daily_budget_usd'], 4),
                        'tokens' => is_int($limit) && $limit > 0 ? round($tokens / $limit, 4) : null,
                    ],
                    'flagged' => false,
                    'causes' => [],
                    'unbooked' => $this->ledger->unbooked($date),
                ],
                'escalations' => $ledger['calls']['escalate'] ?? 0,
            ];
        }

        $out = [];
        $costs = [];
        $tokens = [];
        foreach ($rows as $i => $r) {
            $row = $r['row'];
            if ($row['runs'] > 0) {
                if ($costs !== [] && ($row['cost_usd'] > $c->hygiene->spikeFactor * self::median($costs) || $row['tokens'] > $c->hygiene->spikeFactor * self::median($tokens))) {
                    $row['flagged'] = true;
                    $row['causes'] = $this->causes($gate, $latest, $r['k'], $r['escalations']);
                }
                $costs[] = $row['cost_usd'];
                $tokens[] = $row['tokens'];
            }
            $out[$i] = $row;
        }

        uasort($families, static fn (array $a, array $b): int => $b['tokens'] <=> $a['tokens']);
        $top = [];
        $floor = new \Watcher\Hygiene\LiteralFloor([]); // the same word floor as every other report; the cost report has no pass to count sites in
        foreach (array_slice($families, 0, self::TOP, true) as $fp => $f) {
            $top[] = ['fingerprint' => (string) $fp, 'template' => $f['tpl'] === null ? null : $floor->mask($f['tpl']), 'tokens' => $f['tokens'], 'runs' => $f['runs']];
        }
        $out = array_values($out);

        return [
            'days' => $out,
            'total' => [
                'runs' => array_sum(array_column($out, 'runs')),
                'tokens' => array_sum(array_column($out, 'tokens')),
                'cost_usd' => array_sum(array_column($out, 'cost_usd')),
                'unbooked' => array_sum(array_column($out, 'unbooked')),
            ],
            'top_spenders' => $top,
            'median_cost_usd' => $costs === [] ? 0.0 : self::median($costs),
            'ledger' => $anyLedger,
        ];
    }

    /** @return list<array{cause: string, count: int}> from the day's and the previous day's snapshot and the ledger */
    private function causes(RunGate $gate, DateTimeImmutable $latest, int $k, int $escalations): array
    {
        $key = $this->config->stateKey;
        $cur = $this->store->get(Keys::snapshot($key, Watcher::windowKey($gate->endBack($latest, $k))))['groups'] ?? null;
        $prev = $this->store->get(Keys::snapshot($key, Watcher::windowKey($gate->endBack($latest, $k + 1))))['groups'] ?? null;
        $new = $grown = 0;
        if (is_array($cur) && is_array($prev)) {
            $factor = $this->config->selection['growth_factor'];
            foreach ($cur as $fp => $n) {
                if (!is_int($n)) {
                    continue;
                }
                if (!isset($prev[$fp])) {
                    ++$new;
                } elseif (is_int($prev[$fp]) && $prev[$fp] > 0 && $n >= $factor * $prev[$fp]) {
                    ++$grown;
                }
            }
        }
        $causes = [];
        foreach (['new_families' => $new, 'grown_families' => $grown, 'escalations' => $escalations] as $cause => $count) {
            if ($count > 0) {
                $causes[] = ['cause' => $cause, 'count' => $count];
            }
        }

        return $causes;
    }

    /** @param array<mixed> $a */
    private static function int(array $a, string $key): int
    {
        return is_int($a[$key] ?? null) ? $a[$key] : 0;
    }

    /** @param array<int, int|float> $values */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
