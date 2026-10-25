<?php

declare(strict_types=1);

namespace Watcher\Metrics;

/** Everything one run measured: readings, or the fixed failure token when the backend could not be read. */
final class MetricsContext
{
    /** @param list<MetricReading> $readings */
    public function __construct(
        public readonly array $readings,
        public readonly bool $unavailable = false,
        public readonly ?string $failure = null,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (MetricReading $r): string => $r->name, $this->readings);
    }

    /** @return list<array{?float, ?float}> [current, baseline] at 3 significant digits, null = no data */
    public function pairs(): array
    {
        return array_map(static fn (MetricReading $r): array => [self::sig($r->current), self::sig($r->baseline)], $this->readings);
    }

    /** One digest line; masked text and numbers only. */
    public function line(): string
    {
        if ($this->unavailable) {
            return 'Metrics unavailable (' . ($this->failure ?? 'unreachable') . ')';
        }
        $parts = array_map(static fn (MetricReading $r): string => self::part($r), $this->readings);

        return $parts === [] ? '' : 'Metrics: ' . implode(' · ', $parts);
    }

    private static function part(MetricReading $r): string
    {
        $name = str_replace('_', ' ', $r->name);
        if ($r->state === 'no_data') {
            return "{$name} no data";
        }
        if ($r->state === 'ambiguous') {
            return "{$name} ambiguous ({$r->series} series)";
        }
        $labels = $r->labels === [] ? '' : ' [' . implode(', ', $r->labels) . ']';
        $text = "{$name} " . self::fmt($r->current, $r->unit) . $labels;
        if ($r->state === 'no_baseline') {
            return "{$text} (no baseline)";
        }
        $pct = (int) round((float) $r->relativeChange() * 100);

        return "{$text} (was " . self::fmt($r->baseline, $r->unit) . ', ' . ($pct >= 0 ? '+' : '') . $pct . '%)';
    }

    private static function fmt(?float $v, string $unit): string
    {
        $n = self::num($v);
        $unit = trim($unit);

        return $unit === '' ? $n : ($unit[0] === '%' ? $n . $unit : "{$n} {$unit}");
    }

    private static function sig(?float $v): ?float
    {
        if ($v === null || $v == 0.0) {
            return $v;
        }

        return round($v, 2 - (int) floor(log10(abs($v))));
    }

    private static function num(?float $v): string
    {
        $v = self::sig($v) ?? 0.0;
        $decimals = $v == 0.0 ? 0 : max(0, 2 - (int) floor(log10(abs($v))));
        $s = number_format($v, $decimals, '.', '');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
