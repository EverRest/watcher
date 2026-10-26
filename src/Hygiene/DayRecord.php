<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/**
 * Aggregates of one hygiene pass for one service-local date. Identifiers, categories, levels, integers and times only
 * (data-model.md): there is no field a value or a line could be put in. A stored record that does not validate is ignored.
 *
 * @phpstan-type SiteRow array{key: string, id: string, kind: string, label: ?string, level: string, lines: int, any_hit: int, cats: array<string, int>, long: int, trace: int, mismatch: int, first: string, last: string}
 * @phpstan-type FamilyRow array{fp: string, tpl: ?string, th?: string, level: string, n: int, first: string, last: string, tokens: int}
 * @phpstan-type VariableRow array{tpl: string, positions: int, distinct_messages: int}
 */
final class DayRecord
{
    private const VERSION = 1;

    /**
     * @param list<SiteRow> $sites
     * @param list<FamilyRow> $families
     * @param array<string, int> $formats
     * @param list<VariableRow> $variable
     */
    public function __construct(
        public readonly string $date,
        public readonly int $events,
        public readonly bool $sampled,
        public readonly int $otherEvents,
        public readonly array $sites,
        public readonly array $families,
        public readonly array $formats,
        public readonly array $variable,
    ) {
    }

    /**
     * The record cut down until its JSON is within the given budgets (counted the way the Redis store's codec counts: every `[`, `{` and `,`
     * of the text, punctuation inside strings included). Template texts go first (their hashes stay), then the quietest families and
     * sites; a record that lost anything is marked sampled, so it is not mistaken for a complete day.
     */
    public function withinBudget(int $maxBytes, int $maxContainers, int $maxElements): self
    {
        $fits = static function (self $r) use ($maxBytes, $maxContainers, $maxElements): bool {
            $json = json_encode($r->toArray(), JSON_THROW_ON_ERROR);
            $containers = substr_count($json, '[') + substr_count($json, '{');

            return strlen($json) <= $maxBytes && $containers <= $maxContainers && $containers + substr_count($json, ',') <= $maxElements;
        };
        $r = $this;
        if ($fits($r)) {
            return $r;
        }
        $r = new self($r->date, $r->events, true, $r->otherEvents, $r->sites, array_map(static fn (array $f): array => ['tpl' => null] + $f, $r->families), $r->formats, []);
        $r = new self($r->date, $r->events, true, $r->otherEvents, array_map(static fn (array $s): array => ['label' => null] + $s, $r->sites), $r->families, $r->formats, []);
        while (!$fits($r) && (count($r->families) > 20 || count($r->sites) > 20)) {
            $r = new self($r->date, $r->events, true, $r->otherEvents, array_slice($r->sites, 0, (int) (count($r->sites) * 0.8)), array_slice($r->families, 0, (int) (count($r->families) * 0.8)), $r->formats, []);
        }

        return $r;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'date' => $this->date,
            'events' => $this->events,
            'sampled' => $this->sampled,
            'other_events' => $this->otherEvents,
            'sites' => $this->sites,
            'families' => $this->families,
            'formats' => $this->formats,
            'variable' => $this->variable,
        ];
    }

    /** @param array<mixed> $a */
    public static function fromArray(array $a): ?self
    {
        if (($a['v'] ?? null) !== self::VERSION || !is_string($a['date'] ?? null) || !is_int($a['events'] ?? null)
            || !is_bool($a['sampled'] ?? null) || !is_int($a['other_events'] ?? null)) {
            return null;
        }
        $sites = self::rows($a['sites'] ?? null, self::site(...));
        $families = self::rows($a['families'] ?? null, self::family(...));
        $variable = self::rows($a['variable'] ?? null, self::variableRow(...));
        $formats = $a['formats'] ?? null;
        if ($sites === null || $families === null || $variable === null || !is_array($formats) || !self::ints($formats)) {
            return null;
        }

        return new self($a['date'], $a['events'], $a['sampled'], $a['other_events'], $sites, $families, $formats, $variable);
    }

    /**
     * @template T of array<string, mixed>
     * @param callable(mixed): ?T $check
     * @return list<T>|null
     */
    private static function rows(mixed $rows, callable $check): ?array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            $ok = $check($row);
            if ($ok === null) {
                return null;
            }
            $out[] = $ok;
        }

        return $out;
    }

    private static function time(mixed $v): bool
    {
        return is_string($v) && date_create($v) !== false;
    }

    /** @param array<mixed> $r */
    private static function nullableString(array $r, string $key): bool
    {
        return array_key_exists($key, $r) && ($r[$key] === null || is_string($r[$key]));
    }

    /** @param array<mixed> $a */
    private static function ints(array $a): bool
    {
        foreach ($a as $k => $v) {
            if (!is_string($k) || !is_int($v)) {
                return false;
            }
        }

        return true;
    }

    /** @return SiteRow|null */
    private static function site(mixed $r): ?array
    {
        if (!is_array($r) || !is_string($r['key'] ?? null) || !is_string($r['id'] ?? null) || !is_string($r['kind'] ?? null)
            || !self::nullableString($r, 'label') || !is_string($r['level'] ?? null)
            || !is_int($r['lines'] ?? null) || !is_int($r['any_hit'] ?? null) || !is_array($r['cats'] ?? null) || !self::ints($r['cats'])
            || !is_int($r['long'] ?? null) || !is_int($r['trace'] ?? null) || !is_int($r['mismatch'] ?? null)
            || !self::time($r['first'] ?? null) || !self::time($r['last'] ?? null)) {
            return null;
        }

        return ['key' => $r['key'], 'id' => $r['id'], 'kind' => $r['kind'], 'label' => $r['label'], 'level' => $r['level'], 'lines' => $r['lines'],
            'any_hit' => $r['any_hit'], 'cats' => $r['cats'], 'long' => $r['long'], 'trace' => $r['trace'], 'mismatch' => $r['mismatch'],
            'first' => $r['first'], 'last' => $r['last']];
    }

    /** @return FamilyRow|null */
    private static function family(mixed $r): ?array
    {
        if (!is_array($r) || !is_string($r['fp'] ?? null) || !self::nullableString($r, 'tpl') || !is_string($r['level'] ?? null)
            || !is_int($r['n'] ?? null) || !self::time($r['first'] ?? null) || !self::time($r['last'] ?? null) || !is_int($r['tokens'] ?? null)) {
            return null;
        }

        $row = ['fp' => $r['fp'], 'tpl' => $r['tpl'], 'level' => $r['level'], 'n' => $r['n'], 'first' => $r['first'], 'last' => $r['last'], 'tokens' => $r['tokens']];
        if (is_string($r['th'] ?? null) && preg_match('/\A[0-9a-f]{12}\z/', $r['th']) === 1) {
            $row['th'] = $r['th'];
        }

        return $row;
    }

    /** @return VariableRow|null */
    private static function variableRow(mixed $r): ?array
    {
        if (!is_array($r) || !is_string($r['tpl'] ?? null) || !is_int($r['positions'] ?? null) || !is_int($r['distinct_messages'] ?? null)) {
            return null;
        }

        return ['tpl' => $r['tpl'], 'positions' => $r['positions'], 'distinct_messages' => $r['distinct_messages']];
    }
}
