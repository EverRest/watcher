<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/**
 * US2: log habits that cost reading time: one site producing most of the lines, lines over a limit, traces
 * pasted into messages, mixed formats, access-log lines at error level. Each entry carries its measure and a proposal as
 * fixed text for the owner; nothing is applied (FR-006, US2.2).
 *
 * @phpstan-type Habit array{habit: string, site: ?string, level: ?string, count: int, share: float, measure: array<string, mixed>, proposal: string}
 */
final class ShapeReport implements Report
{
    /** A format class below this share of all lines is not a mix. */
    private const MIX_SHARE = 0.01;

    /**
     * @param list<Habit> $habits
     * @param array<string, int> $formats
     */
    private function __construct(private readonly array $habits, private readonly array $formats)
    {
    }

    public static function build(ScanResult $scan, HygieneSettings $settings): self
    {
        $total = max(1, $scan->events);
        $habits = [];
        foreach ($scan->sites as $s) {
            if ($s->lines / $total > $settings->noisyShare) {
                $habits[] = self::habit('noisy_site', $s->id, $s->level, $s->lines, $total, ['limit' => $settings->noisyShare], self::noisyProposal($s));
            }
        }
        foreach ([
            'long_lines' => ['long', ['limit' => $settings->maxLineLength, 'max_length' => $scan->maxLength], 'shorten the message at the source or log the detail in context fields'],
            'trace_in_message' => ['trace', [], 'log the exception through the logger context, not into the message text'],
        ] as $habit => [$counter, $measure, $proposal]) {
            $worst = self::worst($scan->sites, $counter);
            if ($worst !== null) {
                $habits[] = self::habit($habit, $worst->id, null, self::sum($scan->sites, $counter), $total, $measure, $proposal);
            }
        }

        $formats = $scan->formats;
        arsort($formats);
        $dominant = (string) array_key_first($formats);
        $others = array_filter(array_slice($formats, 1, null, true), static fn (int $n): bool => $n / $total >= self::MIX_SHARE);
        if ($others !== []) {
            ksort($others);
            $habits[] = self::habit('mixed_format', null, null, array_sum($others), $total, ['dominant' => $dominant, 'formats' => $others], 'send access and application lines to separate streams, or mute the minority format');
        }

        $worst = self::worst($scan->sites, 'mismatch');
        if ($worst !== null) {
            $habits[] = self::habit('level_mismatch', $worst->id, null, self::sum($scan->sites, 'mismatch'), $total, [], 'mute the access-log lines or fix the level they are written at');
        }

        return new self($habits, $scan->formats);
    }

    public function name(): string
    {
        return 'shape';
    }

    public function isEmpty(): bool
    {
        return $this->habits === [];
    }

    public function toArray(): array
    {
        return ['habits' => $this->habits, 'formats' => $this->formats];
    }

    public function text(bool $notice = false): string
    {
        if ($this->isEmpty()) {
            return 'shape: nothing to report';
        }
        $out = ['shape: log habits that make everything else harder'];
        foreach ($this->habits as $h) {
            $out[] = sprintf(
                '  %s%s  %d lines (%s%%)  -> %s',
                $h['habit'],
                $h['site'] === null ? '' : ' at ' . ReportText::safe($notice, $h['site']),
                $h['count'],
                rtrim(rtrim(number_format($h['share'] * 100, 1, '.', ''), '0'), '.'),
                ReportText::safe($notice, $h['proposal']),
            );
        }

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $measure
     * @return Habit
     */
    private static function habit(string $habit, ?string $site, ?string $level, int $count, int $total, array $measure, string $proposal): array
    {
        return ['habit' => $habit, 'site' => $site, 'level' => $level, 'count' => $count, 'share' => round($count / $total, 4), 'measure' => $measure, 'proposal' => $proposal];
    }

    private static function noisyProposal(SiteStats $s): string
    {
        if (in_array($s->level, ['debug', 'info', 'notice'], true)) {
            return sprintf("lower the level at %s, or mute it: ['fingerprint' => '%s', 'until' => null]", $s->id, $s->fingerprint);
        }

        return sprintf('find out why %s writes so often at %s', $s->id, $s->level);
    }

    /** @param list<SiteStats> $sites */
    private static function sum(array $sites, string $counter): int
    {
        return array_sum(array_map(static fn (SiteStats $s): int => $s->{$counter}, $sites));
    }

    /** @param list<SiteStats> $sites the site with the most of a counter, or null when it is zero everywhere */
    private static function worst(array $sites, string $counter): ?SiteStats
    {
        $best = null;
        foreach ($sites as $s) {
            if ($s->{$counter} > 0 && ($best === null || $s->{$counter} > $best->{$counter})) {
                $best = $s;
            }
        }

        return $best;
    }
}
