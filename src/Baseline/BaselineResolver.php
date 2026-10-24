<?php

declare(strict_types=1);

namespace Watcher\Baseline;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Schedule\RunGate;
use Watcher\Schedule\Window;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * Builds a Comparison from stored snapshots. Candidate window ends are computed, never listed (StateStore has no
 * list), so a missing snapshot is simply skipped. One snapshot is held at a time; only the requested fingerprints are kept.
 */
final class BaselineResolver
{
    /** @param array{mode: string, windows: int, exclude: list<string>, since: ?string} $baseline */
    public function __construct(
        private readonly StateStore $state,
        private readonly RunGate $gate,
        private readonly string $service,
        private readonly array $baseline,
        private readonly DateTimeZone $timezone,
        private readonly float $growthFactor,
    ) {
    }

    /**
     * @param list<string>             $fingerprints the current run's groups
     * @param array<mixed>|null        $prev         the previous window's snapshot as found by the caller (pointer fallback included)
     * @param DateTimeImmutable|null   $prevEnd      its window end; the window start when null
     */
    public function resolve(Window $window, array $fingerprints, ?array $prev, ?DateTimeImmutable $prevEnd): Comparison
    {
        $mode = $this->baseline['mode'];
        $seconds = $window->end->getTimestamp() - $window->start->getTimestamp();
        $used = [];
        $reason = null;
        $candidates = 0;
        $found = 0;
        $excluded = 0;

        if ($mode !== 'previous') {
            $step = $mode === 'weekday' ? 7 : max(1, (int) round($seconds / 86400));
            $candidates = $this->baseline['windows'];
            for ($k = 1; $k <= $candidates; ++$k) {
                $end = $this->gate->endBack($window->end, $k * $step);
                $local = $end->setTimezone($this->timezone)->format('Y-m-d');
                if ($this->baseline['since'] !== null && $local < $this->baseline['since']) {
                    continue;
                }
                $doc = $this->state->get(Keys::snapshot($this->service, self::key($end)));
                if ($doc === null) {
                    continue;
                }
                if (in_array($local, $this->baseline['exclude'], true)) {
                    ++$excluded;
                    continue;
                }
                $used[] = [$end, $doc];
            }
            $found = count($used);
            if ($found < ($mode === 'weekday' ? 3 : 2)) {
                $used = [];
                $reason = 'short_history';
            }
        }

        $usedMode = $used === [] ? 'previous' : $mode;
        if ($used === [] && $prev !== null) {
            $used = [[$prevEnd ?? $window->start, $prev]];
        }
        $used = array_reverse($used); // ascending ends

        $sets = [];
        $seen = [];
        $lengthChange = null;
        foreach ($used as [$end, $doc]) {
            $factor = 1.0;
            $start = isset($doc['start']) && is_string($doc['start']) ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $doc['start'], new DateTimeZone('UTC')) : false;
            if ($start !== false && $end->getTimestamp() - $start->getTimestamp() !== $seconds && $end > $start) {
                $own = $end->getTimestamp() - $start->getTimestamp();
                $factor = $seconds / $own;
                $lengthChange ??= [$own / 3600.0, $seconds / 3600.0];
            }
            $groups = is_array($doc['groups'] ?? null) ? $doc['groups'] : [];
            foreach ($fingerprints as $fp) {
                $has = isset($groups[$fp]);
                $sets[$fp][] = $has ? $groups[$fp] * $factor : 0.0;
                $seen[$fp] = ($seen[$fp] ?? false) || $has;
            }
        }

        $values = [];
        $rare = [];
        foreach ($sets as $fp => $set) {
            if (!$seen[$fp]) {
                continue;
            }
            $value = match ($usedMode) {
                'weekday' => self::median($set),
                'rolling' => array_sum($set) / count($set),
                default => $set[0],
            };
            if ($value == 0) {
                $value = max($set);
                $rare[$fp] = true;
            }
            $values[$fp] = (float) $value;
        }

        return new Comparison(
            $mode,
            $usedMode,
            $reason,
            array_map(static fn (array $u): DateTimeImmutable => $u[0], $used),
            $candidates,
            $found,
            $excluded,
            $values,
            $rare,
            $this->growthFactor,
            $lengthChange,
        );
    }

    private static function key(DateTimeImmutable $end): string
    {
        return $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** @param non-empty-list<float|int> $set */
    private static function median(array $set): float
    {
        sort($set);
        $n = count($set);

        return $n % 2 === 1 ? (float) $set[intdiv($n, 2)] : ($set[$n / 2 - 1] + $set[$n / 2]) / 2;
    }
}
