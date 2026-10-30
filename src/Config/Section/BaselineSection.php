<?php

declare(strict_types=1);

namespace Watcher\Config\Section;

use Watcher\Config\InvalidConfig;

/** Validates the `baseline` section (comparison windows and exclusions). */
final class BaselineSection
{
    /**
     * @param array<string, mixed> $b
     * @return array{mode: string, windows: int, max_age: string, max_age_seconds: int, exclude: list<string>, since: ?string}
     */
    public static function baseline(array $b, \DateInterval $window, int $retention, int $runsPerDay, int $periodSeconds): array
    {
        SectionFields::only($b, ['mode', 'windows', 'max_age', 'exclude', 'since'], 'baseline');
        $mode = $b['mode'] ?? 'previous';
        if (!in_array($mode, ['previous', 'rolling', 'weekday', 'same_slot_yesterday', 'same_slot_last_week'], true)) {
            throw new InvalidConfig('baseline.mode must be one of previous, rolling, weekday, same_slot_yesterday, same_slot_last_week');
        }
        if (in_array($mode, ['rolling', 'weekday'], true) && ($runsPerDay > 1 || $periodSeconds < 86400)) {
            throw new InvalidConfig('baseline.mode rolling/weekday needs whole-day periods; use same_slot_yesterday or same_slot_last_week with schedule.every');
        }
        if ($mode === 'same_slot_last_week' && $retention < 691200) {
            throw new InvalidConfig('state.retention (691200 s) is shorter than baseline.mode same_slot_last_week needs (691200 s)');
        }
        $min = $mode === 'weekday' ? 3 : 2;
        $windows = $b['windows'] ?? ($mode === 'weekday' ? 4 : 7);
        if ($mode !== 'previous' && (!is_int($windows) || $windows < $min)) {
            throw new InvalidConfig('baseline.windows must be an integer >= ' . $min);
        }
        $maxAge = $b['max_age'] ?? 'P7D';
        try {
            $maxAgeSeconds = is_string($maxAge) ? (new \DateTimeImmutable('@0'))->add(new \DateInterval($maxAge))->getTimestamp() : 0;
        } catch (\Exception) {
            $maxAgeSeconds = 0;
        }
        if (!is_string($maxAge) || $maxAgeSeconds < 1) {
            throw new InvalidConfig('baseline.max_age must be an ISO-8601 duration');
        }
        if ($maxAgeSeconds > $retention) {
            throw new InvalidConfig('baseline.max_age exceeds state.retention');
        }
        $exclude = $b['exclude'] ?? [];
        if (!is_array($exclude) || !array_is_list($exclude) || array_filter($exclude, SectionFields::isDate(...)) !== $exclude) {
            throw new InvalidConfig('baseline.exclude entries must be dates like 2026-12-25');
        }
        $since = $b['since'] ?? null;
        if ($since !== null && !SectionFields::isDate($since)) {
            throw new InvalidConfig('baseline.since must be a date like 2026-10-01');
        }
        if ($mode !== 'previous') {
            $wholeDays = $window->y === 0 && $window->m === 0 && $window->i === 0 && $window->s === 0 && $window->h % 24 === 0
                ? $window->d + intdiv($window->h, 24) : 0;
            if ($wholeDays < 1) {
                throw new InvalidConfig('baseline.mode rolling/weekday needs a whole-day schedule.window');
            }
            $needs = $windows * ($mode === 'weekday' ? 7 : $wholeDays) * 86400 + 86400;
            if ($retention < $needs) {
                throw new InvalidConfig(sprintf('state.retention (%d s) is shorter than baseline.windows needs (%d s)', $retention, $needs));
            }
        }

        return [
            'mode' => $mode,
            'windows' => is_int($windows) ? $windows : 0,
            'max_age' => $maxAge,
            'max_age_seconds' => $maxAgeSeconds,
            'exclude' => $exclude,
            'since' => $since,
        ];
    }
}
