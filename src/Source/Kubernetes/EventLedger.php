<?php

declare(strict_types=1);

namespace Watcher\Source\Kubernetes;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Schedule\Window;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * Per-service, expiring record of deduplicated pod events (spec 008 FR-007, FR-009, research R6). The cluster forgets an
 * event about an hour after it last changed, the digest runs daily, so every hourly trigger merges what is still there:
 * for each event key only the INCREASE of its count is added, stamped with the event's own last timestamp.
 *
 * Persisted: masked text, level inputs, counts, one-way pod hashes, unix times. Never a raw event, a name or an image.
 * Every write carries an expiry. A record that does not parse is treated as empty (fail closed, nothing is guessed).
 */
final class EventLedger
{
    public const VERSION = 3;
    private const MAX_GAPS = 20;
    private const CLASSES = ['normal', 'warning', 'critical'];
    private const MAX_UNATTRIBUTED = 2000;
    private const MAX_POINTS = 48; // increments kept per entry (two days of hourly reads): a restart storm must not grow the record without bound

    /**
     * The Redis store refuses a value past MAX_BYTES 4 MiB, MAX_ELEMENTS 100 000 and MAX_CONTAINERS 16 000, counted on the JSON text
     * (every `[`, `{` and `,`, punctuation inside strings included; origin/develop src/State/StateCodec.php). The record is kept to half
     * of each, however large the cluster: entries that do not fit are dropped oldest-seen first and counted in `ovf`.
     */
    private const BUDGET_BYTES = 2 * 1024 * 1024;
    private const BUDGET_CONTAINERS = 8000;
    private const BUDGET_ELEMENTS = 50000;
    /** A read this much later than the cluster's retention is still jitter (cron, boot, earlier fleet entries), not a gap. */
    private const GAP_SLACK = 600;

    /** @var array<string, mixed> */
    private array $record;

    /**
     * @param int $windowSeconds length of the digest window
     * @param int $eventTtl      the cluster's event retention in seconds: a longer silence is a gap
     * @param int $ttl           expiry of the stored record
     */
    public function __construct(
        private readonly StateStore $store,
        private readonly string $service,
        private readonly int $windowSeconds,
        private readonly int $tolerance,
        private readonly int $eventTtl,
        private readonly int $ttl,
        private readonly int $cap = 5000,
    ) {
        $this->record = self::empty();
    }

    /**
     * How long a record must live and how far back it looks: the window, a day of slack (a dry run or a manual run may come
     * hours after the window ended; a window is 25 h on a clock-change day) and the tolerance.
     */
    public static function keepSeconds(int $windowSeconds, int $tolerance): int
    {
        return $windowSeconds + 86400 + 3600 + $tolerance;
    }

    /** True once at least one read was merged: the record is worth replaying even if a later read fails. */
    public function hasData(): bool
    {
        return $this->record['last_ok'] !== null;
    }

    public function load(): void
    {
        $raw = $this->store->get(Keys::events($this->service));
        $this->record = self::valid($raw) ? self::expand($raw) : self::empty();
    }

    public function save(): void
    {
        $this->fit();
        $this->store->put(Keys::events($this->service), $this->stored(), $this->ttl);
    }

    /** Entries dropped because the record would not fit the state store's budget (ever, within the record's life). */
    public function overflow(): int
    {
        return $this->record['ovf'];
    }

    /**
     * Drops entries until the encoded record is within half of the codec's budgets: the LOWEST severity class first (normal, then
     * warning, then critical), inside a class the least recently seen and the lowest count. Every drop is counted per class.
     */
    private function fit(): void
    {
        while ($this->record['e'] !== []) {
            $json = json_encode($this->stored(), JSON_THROW_ON_ERROR);
            $containers = substr_count($json, '[') + substr_count($json, '{');
            if (strlen($json) <= self::BUDGET_BYTES && $containers <= self::BUDGET_CONTAINERS && $containers + substr_count($json, ',') <= self::BUDGET_ELEMENTS) {
                return;
            }
            $this->shed($this->record, max(1, intdiv(count($this->record['e']), 10)), $this->record['last_ok'] ?? time());
        }
    }

    /**
     * @param array<string, mixed> $r
     */
    private function shed(array &$r, int $n, int $now): void
    {
        uasort($r['e'], static fn (array $a, array $b): int => [self::rank($a['typ'], $a['rsn']), $a['seen'], $a['c']] <=> [self::rank($b['typ'], $b['rsn']), $b['seen'], $b['c']]);
        foreach (array_slice($r['e'], 0, $n, true) as $dropped) {
            $class = self::CLASSES[self::rank($dropped['typ'], $dropped['rsn'])];
            $r['ovfc'][$class] = ($r['ovfc'][$class] ?? 0) + 1;
            ++$r['ovf'];
        }
        $r['e'] = array_slice($r['e'], $n, null, true);
        ksort($r['e']);
        $r['capped'] = $now;
    }

    /** 2 critical (the pod was killed, evicted, failed or its node went away), 1 warning (any other Warning event), 0 normal. */
    private static function rank(string $type, string $reason): int
    {
        if (preg_match('/^(?:OOM|Evict|NodeNotReady|Failed|NodeHas|SystemOOM|Preempt)/i', $reason) === 1) {
            return 2;
        }

        return strcasecmp($type, 'Warning') === 0 ? 1 : 0;
    }

    /**
     * The record as stored: the numbers of an entry in one string, so an entry is one small object (commas are what the codec budgets).
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $r = $this->record;
        foreach ($r['e'] as $key => $e) {
            $r['e'][$key] = ['typ' => $e['typ'], 'rsn' => $e['rsn'], 'wk' => $e['wk'], 'wl' => $e['wl'], 'txt' => $e['txt'], 'pod' => $e['pod'],
                'n' => $e['c'] . ':' . $e['t'] . ':' . $e['f'] . ':' . $e['seen'] . ':' . ($e['rs'] ? 1 : 0), 'd' => $e['d']];
        }

        return $r;
    }

    /**
     * @param array<string, mixed> $raw a record that passed valid()
     * @return array<string, mixed>
     */
    private static function expand(array $raw): array
    {
        foreach ($raw['e'] as $key => $e) {
            [$c, $t, $f, $seen, $rs] = array_map('intval', explode(':', $e['n']));
            $raw['e'][$key] = ['typ' => $e['typ'], 'rsn' => $e['rsn'], 'wk' => $e['wk'], 'wl' => $e['wl'], 'txt' => $e['txt'], 'pod' => $e['pod'],
                'rs' => $rs === 1, 'c' => $c, 't' => $t, 'f' => $f, 'seen' => $seen, 'd' => $e['d']];
        }

        return $raw;
    }

    /** @return list<array{int, int}> */
    private static function points(string $packed): array
    {
        $out = [];
        foreach (explode(';', $packed) as $p) {
            $pair = explode(':', $p);
            // Digits of at most 15 places: a longer run would not cast to int without a warning (stored data is not trusted to be small).
            if (count($pair) === 2 && ctype_digit($pair[0]) && ctype_digit($pair[1]) && strlen($pair[0]) <= 15 && strlen($pair[1]) <= 15) {
                $out[] = [(int) $pair[0], (int) $pair[1]];
            }
        }

        return $out;
    }

    /** @param list<array{int, int}> $points One flat string per entry: no container and no comma per point. */
    private static function pack(array $points): string
    {
        return implode(';', array_map(static fn (array $p): string => $p[0] . ':' . $p[1], $points));
    }

    public function merge(MappedRead $read, DateTimeImmutable $now): void
    {
        $at = $now->getTimestamp();
        $r = $this->record;
        $previousRead = $r['last_ok'];
        if ($r['last_ok'] === null) {
            $r['since'] = $at;
        } elseif ($at - $r['last_ok'] > $this->eventTtl + self::GAP_SLACK) {
            $r['gaps'][] = [$r['last_ok'], $at];
            $r['gaps'] = array_slice($r['gaps'], -self::MAX_GAPS);
        }
        $r['last_ok'] = $at;

        foreach ($read->observations as $o) {
            $ts = $o->last->getTimestamp();
            $e = $r['e'][$o->key] ?? null;
            if ($e === null) {
                // An object that already existed before the previous read (or, on a new ledger, before the cluster's retention)
                // carries occurrences from before this ledger: its count is a baseline, and only one occurrence (the last) is
                // credited. An object created since the previous read is credited in full.
                $delta = $o->first->getTimestamp() > ($previousRead ?? $at - $this->eventTtl) ? $o->count : min(1, $o->count);
                $e = ['typ' => $o->type, 'rsn' => $o->reason, 'wk' => $o->workloadKind, 'wl' => $o->workload, 'txt' => $o->text,
                    'pod' => $o->podHash, 'rs' => $o->restarted, 'c' => $o->count, 't' => $ts, 'f' => $o->first->getTimestamp(), 'seen' => $at, 'd' => ''];
            } else {
                $delta = 0;
                if ($o->count > $e['c']) {
                    $delta = $o->count - $e['c'];
                } elseif ($ts > $e['t']) {
                    $delta = $o->count; // the event object was recreated: its count started again
                }
                $e['c'] = $o->count;
                $e['t'] = max($e['t'], $ts);
                $e['seen'] = $at;
                $e['rs'] = $e['rs'] || $o->restarted;
            }
            if ($delta > 0) {
                $points = array_slice(self::points($e['d']), -self::MAX_POINTS);
                $last = count($points) - 1;
                if ($last >= 0 && $points[$last][0] === $ts) {
                    $points[$last][1] += (int) $delta;
                } else {
                    $points[] = [$ts, (int) $delta];
                }
                $e['d'] = self::pack($points);
            }
            $r['e'][$o->key] = $e;
        }
        foreach ($read->unattributed as $key) {
            $r['un'][$key] = $at;
        }

        $this->record = $this->prune($r, $at);
    }

    /** @return list<LedgerEntry> entries with occurrences in the window, in key order */
    public function window(Window $window): array
    {
        [$lo, $hi] = $this->range($window);
        $out = [];
        foreach ($this->record['e'] as $e) {
            $count = 0;
            $min = null;
            $max = null;
            foreach (self::points($e['d']) as [$ts, $n]) {
                if ($ts >= $lo && $ts < $hi) {
                    $count += $n;
                    $min = min($min ?? $ts, $ts);
                    $max = max($max ?? $ts, $ts);
                }
            }
            if ($count > 0) {
                $out[] = new LedgerEntry($e['typ'], $e['rsn'], $e['wk'], $e['wl'], $e['txt'], $e['pod'], $e['rs'], $count, self::time((int) $min), self::time((int) $max));
            }
        }

        return $out;
    }

    public function coverage(Window $window): Coverage
    {
        $start = $window->start->getTimestamp();
        $end = $window->end->getTimestamp();
        $gaps = [];
        foreach ($this->record['gaps'] as [$from, $to]) {
            if ($to > $start && $from < $end) {
                $gaps[] = [self::time($from), self::time($to)];
            }
        }
        $since = $this->record['since'];
        [$lo, $hi] = $this->range($window);
        $un = count(array_filter($this->record['un'], static fn (int $ts): bool => $ts >= $lo && $ts < $hi));

        $cap = $this->record['capped'];

        return new Coverage($gaps, $since === null ? null : self::time($since), $since !== null && $since > $start + $this->tolerance, $un, is_int($cap) && $cap >= $start - $this->tolerance && $cap <= $end + 3600, $this->record['ovf'], array_map('intval', $this->record['ovfc']));
    }

    /** @return array{int, int} Shifting both ends by the tolerance keeps consecutive windows a partition. */
    private function range(Window $w): array
    {
        return [$w->start->getTimestamp() - $this->tolerance, $w->end->getTimestamp() - $this->tolerance];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function prune(array $r, int $now): array
    {
        $horizon = $now - self::keepSeconds($this->windowSeconds, $this->tolerance);
        foreach ($r['e'] as $key => $e) {
            // Forgotten only when the cluster stopped listing it: an object still listed keeps its count as the baseline,
            // however old its own timestamp, or its history would be counted again when it next changes.
            if ($e['seen'] < $horizon) {
                unset($r['e'][$key]);
                continue;
            }
            $r['e'][$key]['d'] = self::pack(array_values(array_filter(self::points($e['d']), static fn (array $p): bool => $p[0] >= $horizon)));
        }
        if (count($r['e']) > $this->cap) {
            $this->shed($r, count($r['e']) - $this->cap, $now);
        }
        $r['gaps'] = array_values(array_filter($r['gaps'], static fn (array $g): bool => $g[1] >= $horizon));
        $r['un'] = array_filter($r['un'], static fn (int $ts): bool => $ts >= $horizon);
        if (count($r['un']) > self::MAX_UNATTRIBUTED) {
            asort($r['un']);
            $r['un'] = array_slice($r['un'], -self::MAX_UNATTRIBUTED, null, true);
        }

        return $r;
    }

    /** @return array<string, mixed> */
    private static function empty(): array
    {
        return ['v' => self::VERSION, 'last_ok' => null, 'since' => null, 'gaps' => [], 'un' => [], 'e' => [], 'capped' => false, 'ovf' => 0, 'ovfc' => []]; // capped: false, or the unix time entries were last dropped
    }

    /**
     * @param array<mixed>|null $raw
     * @phpstan-assert-if-true array<string, mixed> $raw
     */
    private static function valid(?array $raw): bool
    {
        if ($raw === null || ($raw['v'] ?? null) !== self::VERSION || !is_array($raw['e'] ?? null) || !is_array($raw['gaps'] ?? null)
            || !is_array($raw['un'] ?? null) || !array_key_exists('last_ok', $raw) || !array_key_exists('since', $raw) || !(is_bool($raw['capped'] ?? null) || is_int($raw['capped'] ?? null)) || !is_int($raw['ovf'] ?? null) || !is_array($raw['ovfc'] ?? null)) {
            return false;
        }
        foreach ($raw['e'] as $e) {
            if (!is_array($e) || !isset($e['typ'], $e['rsn'], $e['wk'], $e['wl'], $e['txt']) || !is_string($e['n'] ?? null) || preg_match('/\A\d+:\d+:\d+:\d+:[01]\z/', $e['n']) !== 1 || !is_string($e['d'] ?? null) || !array_key_exists('pod', $e)) {
                return false;
            }
        }

        return true;
    }

    private static function time(int $ts): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
    }
}
