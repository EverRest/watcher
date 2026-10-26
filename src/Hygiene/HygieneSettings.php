<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use Watcher\Config\InvalidConfig;
use Watcher\Level;

/** The `hygiene` configuration section (spec 006, contracts/config.md). Off unless `enabled` is true. */
final class HygieneSettings
{
    private const KEYS = [
        'enabled', 'read_min_level', 'event_cap', 'max_line_length', 'noisy_share', 'variable_distinct', 'repeat_days',
        'growth_factor', 'min_history_days', 'mute_min_daily', 'watch_lead', 'decline_days', 'spike_factor', 'max_seconds', 'site_allow_dirs',
        'allow_constant_labels', 'literal_min_sites',
    ];

    public function __construct(
        public readonly bool $enabled = false,
        public readonly Level $readMinLevel = Level::Info,
        public readonly int $eventCap = 200000,
        public readonly int $maxLineLength = 2000,
        public readonly float $noisyShare = 0.20,
        public readonly int $variableDistinct = 20,
        public readonly int $repeatDays = 7,
        public readonly float $growthFactor = 2.0,
        public readonly int $minHistoryDays = 14,
        public readonly int $muteMinDaily = 100,
        public readonly int $watchLead = 1800,
        public readonly int $declineDays = 90,
        public readonly float $spikeFactor = 2.0,
        public readonly int $maxSeconds = 900,
        /** @var list<string> directory names a site may sit under although the deny list names them (lower case) */
        public readonly array $siteAllowDirs = [],
        /** a message that never varies keeps its label and can be proposed as a watch rule (it may be one person's name: off by default) */
        public readonly bool $allowConstantLabels = false,
        /** a literal word of a shown template must be common log vocabulary or appear at this many different sites in the pass */
        public readonly int $literalMinSites = 5,
    ) {
    }

    /** @param array<array-key, mixed> $s */
    public static function fromArray(array $s): self
    {
        foreach (array_keys($s) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidConfig('Unknown configuration key: hygiene.' . $key);
            }
        }
        $d = new self();
        $enabled = $s['enabled'] ?? $d->enabled;
        if (!is_bool($enabled)) {
            throw new InvalidConfig('hygiene.enabled must be true or false');
        }
        $level = $s['read_min_level'] ?? null;
        $readMin = $level === null ? $d->readMinLevel : (is_string($level) ? Level::tryParse($level) : null);
        if ($readMin === null) {
            throw new InvalidConfig('hygiene.read_min_level must be a level name');
        }
        $share = self::number($s, 'noisy_share', $d->noisyShare);
        if ($share <= 0 || $share > 1) {
            throw new InvalidConfig('hygiene.noisy_share must be greater than 0 and at most 1');
        }

        return new self(
            $enabled,
            $readMin,
            self::int($s, 'event_cap', $d->eventCap),
            self::int($s, 'max_line_length', $d->maxLineLength),
            $share,
            self::atLeast(self::int($s, 'variable_distinct', $d->variableDistinct), 5, 'variable_distinct'),
            self::int($s, 'repeat_days', $d->repeatDays),
            self::factor($s, 'growth_factor', $d->growthFactor),
            self::int($s, 'min_history_days', $d->minHistoryDays),
            self::int($s, 'mute_min_daily', $d->muteMinDaily),
            self::int($s, 'watch_lead', $d->watchLead),
            self::int($s, 'decline_days', $d->declineDays),
            self::factor($s, 'spike_factor', $d->spikeFactor),
            self::int($s, 'max_seconds', $d->maxSeconds),
            self::dirs($s),
            self::flag($s, 'allow_constant_labels', $d->allowConstantLabels),
            self::atLeast(self::int($s, 'literal_min_sites', $d->literalMinSites), 2, 'literal_min_sites'),
        );
    }

    /**
     * @param array<array-key, mixed> $s
     * @return list<string>
     */
    private static function dirs(array $s): array
    {
        $v = $s['site_allow_dirs'] ?? [];
        if (!is_array($v) || !array_is_list($v)) {
            throw new InvalidConfig('hygiene.site_allow_dirs must be a list of directory names');
        }
        foreach ($v as $dir) {
            if (!is_string($dir) || $dir === '' || str_contains($dir, '/') || str_contains($dir, '\\')) {
                throw new InvalidConfig('hygiene.site_allow_dirs must be a list of directory names (no path separators)');
            }
        }

        return array_map(strtolower(...), $v);
    }

    /** @param array<array-key, mixed> $s */
    private static function flag(array $s, string $key, bool $default): bool
    {
        $v = $s[$key] ?? $default;
        if (!is_bool($v)) {
            throw new InvalidConfig('hygiene.' . $key . ' must be true or false');
        }

        return $v;
    }

    /** @param array<array-key, mixed> $s */
    private static function int(array $s, string $key, int $default): int
    {
        $v = $s[$key] ?? $default;
        if (!is_int($v) || $v < 1) {
            throw new InvalidConfig('hygiene.' . $key . ' must be a positive integer');
        }

        return $v;
    }

    /** The k-anonymity floor: a template folding fewer than five texts says too much about each. */
    private static function atLeast(int $v, int $min, string $key): int
    {
        if ($v < $min) {
            throw new InvalidConfig('hygiene.' . $key . ' must be at least ' . $min);
        }

        return $v;
    }

    /** @param array<array-key, mixed> $s */
    private static function number(array $s, string $key, float $default): float
    {
        $v = $s[$key] ?? $default;
        if (!is_int($v) && !is_float($v)) {
            throw new InvalidConfig('hygiene.' . $key . ' must be a number');
        }

        return (float) $v;
    }

    /** @param array<array-key, mixed> $s */
    private static function factor(array $s, string $key, float $default): float
    {
        $v = self::number($s, $key, $default);
        if ($v <= 1) {
            throw new InvalidConfig('hygiene.' . $key . ' must be greater than 1');
        }

        return $v;
    }
}
