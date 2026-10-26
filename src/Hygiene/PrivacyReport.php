<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/**
 * US1: the call sites that write personal-data patterns, by category and level, with counts, since when and a trend; and
 * the templates that hide many different texts behind one sentence. Identifiers, categories, counts and times only:
 * no value, no example line, no field (FR-006).
 *
 * @phpstan-type Row array{key: string, site: string, kind: string, label: ?string, level: string, category: string, count: int, lines: int, share_of_lines: float, first_seen: string, last_seen: string, trend: string, reportable: bool}
 * @phpstan-type Var array{key: string, template: string, positions: int, distinct_messages: int, trend: string, reportable: bool}
 */
final class PrivacyReport implements Report
{
    /**
     * @param list<Row> $rows
     * @param list<Var> $variable
     * @param array<string, string> $gone key => identifier
     */
    private function __construct(
        private readonly array $rows,
        private readonly array $variable,
        private readonly array $gone,
        private readonly int $otherEvents,
        private readonly int $omitted = 0,
        private readonly int $unscannable = 0,
    ) {
    }

    /**
     * Observes the sites in the registry (trend and since-when come from it); the caller stores the registry afterwards.
     *
     * @param array<string, ?string> $labels fingerprint => template already allowed to leave the service
     * @param list<array{tpl: string, th?: string, positions: int, distinct_messages: int}> $variable
     */
    public static function build(ScanResult $scan, array $labels, array $variable, SiteRegistry $registry, bool $complete = true): self
    {
        $current = [];
        foreach ($scan->sites as $s) {
            if ($s->anyHit > 0) {
                $current[$s->key] = ['id' => $s->id, 'kind' => $s->kind, 'count' => $s->anyHit, 'first' => $s->first];
            }
        }
        foreach ($variable as $v) {
            $current[self::variableKey($v)] = ['id' => $v['tpl'], 'kind' => 'variable', 'count' => $v['distinct_messages'], 'first' => $registry->now()];
        }
        $registry->observe($current, $complete);

        $rows = [];
        foreach ($scan->sites as $s) {
            if ($s->anyHit === 0) {
                continue;
            }
            foreach (Category::cases() as $c) {
                $n = $s->cats[$c->value] ?? 0;
                if ($n > 0) {
                    $rows[] = [
                        'key' => $s->key, 'site' => $s->id, 'kind' => $s->kind, 'label' => $labels[$s->fingerprint] ?? null, 'level' => $s->level,
                        'category' => $c->value, 'count' => $n, 'lines' => $s->lines, 'share_of_lines' => round($n / $s->lines, 4),
                        'first_seen' => $registry->since($s->key) ?? $s->first->format(DATE_ATOM), 'last_seen' => $s->last->format(DATE_ATOM),
                        'trend' => $registry->trend($s->key), 'reportable' => $registry->reportable($s->key),
                    ];
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['site']] <=> [$a['count'], $b['site']]);

        $vars = [];
        foreach ($variable as $v) {
            $key = self::variableKey($v);
            $vars[] = ['key' => $key, 'template' => $v['tpl'], 'positions' => $v['positions'], 'distinct_messages' => $v['distinct_messages'],
                'trend' => $registry->trend($key), 'reportable' => $registry->reportable($key)];
        }

        return new self($rows, $vars, $registry->gone(), $scan->otherEvents, 0, $scan->unscannable);
    }

    public function name(): string
    {
        return 'privacy';
    }

    /**
     * What is worth sending: new, growing and due sites and the retractions (FR-007), at most `$maxSites` sites and `$maxVariable`
     * templates. What does not fit is counted, not marked as sent, so it comes in the next notice (a Slack message cut at its
     * limit must never silence a site for a week).
     */
    public function notice(int $maxSites = 25, int $maxVariable = 10): self
    {
        $kept = [];
        $shown = [];
        $cut = [];
        foreach (array_filter($this->rows, static fn (array $r): bool => $r['reportable']) as $r) {
            if (!isset($shown[$r['key']]) && count($shown) >= $maxSites) {
                $cut[$r['key']] = true;

                continue;
            }
            $shown[$r['key']] = true;
            $kept[] = $r;
        }
        $variable = array_values(array_filter($this->variable, static fn (array $v): bool => $v['reportable']));

        return new self($kept, array_slice($variable, 0, $maxVariable), array_slice($this->gone, 0, $maxSites, true), $this->otherEvents, count($cut) + max(0, count($variable) - $maxVariable), $this->unscannable);
    }

    /** @return list<string> registry keys of the sites in this report, to mark as sent */
    public function sentKeys(): array
    {
        return array_values(array_unique([...array_column($this->rows, 'key'), ...array_column($this->variable, 'key')]));
    }

    /** @return list<string> */
    public function goneKeys(): array
    {
        return array_keys($this->gone);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [] && $this->variable === [] && $this->gone === [] && $this->omitted === 0;
    }

    public function toArray(): array
    {
        return [
            'sites' => array_map(static fn (array $r): array => array_diff_key($r, ['key' => 1, 'reportable' => 1]), $this->rows),
            'variable_text' => array_map(static fn (array $v): array => array_diff_key($v, ['key' => 1, 'reportable' => 1]), $this->variable),
            'gone' => array_values($this->gone),
            'other_events' => $this->otherEvents,
            'unscannable' => $this->unscannable,
        ];
    }

    public function text(bool $notice = false): string
    {
        if ($this->isEmpty()) {
            return 'privacy: nothing to report';
        }
        $out = ['privacy: log sites that write personal data'];
        foreach ($this->rows as $r) {
            $out[] = sprintf(
                '  %s  %s  %s  %d of %d lines (%s%%)  since %s  [%s]',
                ReportText::safe($notice, $r['site']),
                $r['level'],
                $r['category'],
                $r['count'],
                $r['lines'],
                rtrim(rtrim(number_format($r['share_of_lines'] * 100, 1, '.', ''), '0'), '.'),
                substr($r['first_seen'], 0, 10),
                $r['trend'],
            );
        }
        if ($this->variable !== []) {
            $out[] = 'variable free text, possible personal data:';
            foreach ($this->variable as $v) {
                $out[] = sprintf('  "%s"  %d distinct messages  [%s]', ReportText::safe($notice, $v['template']), $v['distinct_messages'], $v['trend']);
            }
        }
        if ($this->gone !== []) {
            // One id per line: the notice splitter caps a line, so a joined list would lose its tail while all of it is marked as sent (review B-1).
            $out[] = 'gone since the last report:';
            foreach ($this->gone as $g) {
                $out[] = '  ' . ReportText::safe($notice, $notice ? mb_strimwidth($g, 0, 300, '…') : $g);
            }
        }
        if ($this->omitted > 0) {
            $out[] = sprintf('and %d more site(s) not listed here; they follow in the next report', $this->omitted);
        }
        if ($this->otherEvents > 0) {
            $out[] = sprintf('note: %d events outside the site table were not attributed', $this->otherEvents);
        }

        if ($this->unscannable > 0) {
            $out[] = sprintf('note: %d texts could not be scanned (invalid encoding) and are not counted in any category', $this->unscannable);
        }

        return implode("\n", $out);
    }

    /** @param array{tpl: string, th?: string} $row keyed on the UNfloored template hash when given: a word crossing the floor must not flip the key */
    private static function variableKey(array $row): string
    {
        return 'v' . ($row['th'] ?? substr(hash('sha256', 'v|' . $row['tpl']), 0, 15));
    }
}
