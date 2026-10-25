<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use DateTimeImmutable;

/**
 * What the owner has already been told about each call site (FR-007). A site is `new` until it has been sent, `growing`
 * when its count passes `growth_factor` times the count at the last send, `stable` otherwise (repeated at most every
 * `repeat_days`), and `gone` once, after it was sent and then stopped appearing for `max(repeat_days, 2)` complete days. An entry holds an identifier, times and
 * two integers: nothing of any log line. A site is matched by its key, which ignores the line number (data-model.md).
 */
final class SiteRegistry
{
    private const MAX_ENTRIES = 2000;

    /** @var array<string, array{id: string, kind: string, first: string, last: string, reportedAt: ?string, reportedCount: ?int, seen: bool, gonePending: bool, absent: int, absentOn: ?string, goneSentAt: ?string}> */
    private array $entries = [];

    /** @var array<string, string> */
    private array $trends = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @param array<string, array<string, mixed>> $stored as returned by {@see HygieneHistory::registry()} */
    public function __construct(
        array $stored,
        private readonly DateTimeImmutable $now,
        private readonly HygieneSettings $settings,
        private readonly int $retention,
    ) {
        foreach ($stored as $key => $e) {
            // One entry with an unreadable time is dropped, never allowed to stop the pass (review L8).
            if (is_string($e['id'] ?? null) && is_string($e['kind'] ?? null) && self::time($e['first'] ?? null) && self::time($e['last'] ?? null)) {
                $this->entries[(string) $key] = [
                    'id' => $e['id'], 'kind' => $e['kind'], 'first' => $e['first'], 'last' => $e['last'],
                    'reportedAt' => self::time($e['reportedAt'] ?? null) ? $e['reportedAt'] : null,
                    'reportedCount' => is_int($e['reportedCount'] ?? null) ? $e['reportedCount'] : null,
                    'seen' => ($e['seen'] ?? false) === true,
                    'gonePending' => ($e['gonePending'] ?? false) === true,
                    'absent' => is_int($e['absent'] ?? null) ? $e['absent'] : 0,
                    'absentOn' => is_string($e['absentOn'] ?? null) ? $e['absentOn'] : null,
                    'goneSentAt' => self::time($e['goneSentAt'] ?? null) ? $e['goneSentAt'] : null,
                ];
            }
        }
    }

    /**
     * @param array<string, array{id: string, kind: string, count: int, first: DateTimeImmutable}> $current key => what this pass saw
     * @param bool $complete false for a sampled read: it cannot show that a site stopped appearing
     */
    public function observe(array $current, bool $complete = true): void
    {
        $this->trends = [];
        $this->counts = [];
        $atom = $this->now->format(DATE_ATOM);
        foreach ($current as $key => $c) {
            $e = $this->entries[$key] ?? null;
            $this->counts[$key] = $c['count'];
            $this->trends[$key] = match (true) {
                $e === null || $e['reportedAt'] === null => 'new',
                $e['reportedCount'] !== null && $e['reportedCount'] > 0 && $c['count'] >= $this->settings->growthFactor * $e['reportedCount'] => 'growing',
                default => 'stable',
            };
            $first = $e === null ? $c['first']->format(DATE_ATOM) : min($e['first'], $c['first']->format(DATE_ATOM));
            $this->entries[$key] = [
                'id' => $c['id'], 'kind' => $c['kind'], 'first' => $first, 'last' => $atom,
                'reportedAt' => $e['reportedAt'] ?? null, 'reportedCount' => $e['reportedCount'] ?? null,
                'seen' => true, 'gonePending' => false, 'absent' => 0, 'absentOn' => null, 'goneSentAt' => $e === null ? null : ($e['goneSentAt'] ?? null),
            ];
        }
        // Gone needs max(repeat_days, 2) complete quiet days without the site: a weekly job is quiet six days in seven, a weekend or a backfill proves nothing (review M4, B-3).
        $today = $this->now->format('Y-m-d');
        foreach ($this->entries as $key => $e) {
            if (isset($current[$key]) || !$e['seen'] || !$complete) {
                continue;
            }
            if ($e['absentOn'] !== $today) {
                $this->entries[$key]['absent'] = $e['absent'] + 1;
                $this->entries[$key]['absentOn'] = $today;
            }
            if ($this->entries[$key]['absent'] >= max($this->settings->repeatDays, 2)) {
                $this->entries[$key]['seen'] = false;
                $this->entries[$key]['gonePending'] = $e['reportedAt'] !== null;
            }
        }
        $this->prune();
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function trend(string $key): string
    {
        return $this->trends[$key] ?? 'new';
    }

    /** First seen, as kept from the first pass that saw the site. */
    public function since(string $key): ?string
    {
        return $this->entries[$key]['first'] ?? null;
    }

    public function reportable(string $key): bool
    {
        $trend = $this->trend($key);
        if ($trend !== 'stable') {
            return true;
        }
        $at = $this->entries[$key]['reportedAt'] ?? null;

        return $at === null || $this->now->getTimestamp() - (new DateTimeImmutable($at))->getTimestamp() >= $this->settings->repeatDays * 86400;
    }

    /** @return array<string, string> key => identifier of the sites that stopped appearing and have not yet been retracted */
    public function gone(): array
    {
        $out = [];
        foreach ($this->entries as $key => $e) {
            if ($e['gonePending']) {
                $out[$key] = $e['id'];
            }
        }

        return $out;
    }

    /** @param list<string> $keys sites that were just sent */
    public function markReported(array $keys): void
    {
        foreach ($keys as $key) {
            if (isset($this->entries[$key], $this->counts[$key])) {
                $this->entries[$key]['reportedAt'] = $this->now->format(DATE_ATOM);
                $this->entries[$key]['reportedCount'] = $this->counts[$key];
            }
        }
    }

    /** @param list<string> $keys */
    public function markGoneReported(array $keys): void
    {
        foreach ($keys as $key) {
            if (isset($this->entries[$key])) {
                $this->entries[$key]['gonePending'] = false;
                $this->entries[$key]['goneSentAt'] = $this->now->format(DATE_ATOM);
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return $this->entries;
    }

    private static function time(mixed $v): bool
    {
        return is_string($v) && date_create($v) !== false;
    }

    private function prune(): void
    {
        $oldest = $this->now->getTimestamp() - $this->retention;
        $this->entries = array_filter($this->entries, static fn (array $e): bool => (new DateTimeImmutable($e['last']))->getTimestamp() >= $oldest);
        if (count($this->entries) > self::MAX_ENTRIES) {
            // Most recently seen first, compared as instants; equal instants by key, so the survivors do not depend on insertion order.
            $at = static fn (array $e): int => (new DateTimeImmutable($e['last']))->getTimestamp();
            $keys = array_keys($this->entries);
            usort($keys, fn (string $a, string $b): int => [$at($this->entries[$b]), $a] <=> [$at($this->entries[$a]), $b]);
            $kept = [];
            foreach (array_slice($keys, 0, self::MAX_ENTRIES) as $key) {
                $kept[$key] = $this->entries[$key];
            }
            $this->entries = $kept;
        }
    }
}
