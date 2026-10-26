<?php

declare(strict_types=1);

namespace Watcher\Metrics;

use Throwable;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Schedule\Window;
use Watcher\Source\SourceUnavailable;

/**
 * Runs the declared queries for the window and the previous window of equal length (research R4) and reduces each to
 * one number. Never throws: a failing backend yields an `unavailable` context carrying a fixed token.
 */
final class MetricsReader
{
    private const TOKENS = ['unreachable', 'unauthorized', 'timeout', 'bad_response', 'rate_limited', 'too_large'];

    /** @param list<MetricDefinition> $metrics */
    public function __construct(
        private readonly MetricSource $source,
        private readonly array $metrics,
        private readonly int $maxPoints = 500,
        private readonly Normalizer $normalizer = new Normalizer(),
        private ?Redactor $redactor = null,
    ) {
        $this->redactor ??= new Redactor($normalizer);
    }

    public function read(Window $window): MetricsContext
    {
        $length = $window->end->getTimestamp() - $window->start->getTimestamp();
        $baseline = new Window($window->start->modify("-{$length} seconds"), $window->start);
        try {
            $readings = array_map(fn (MetricDefinition $m): MetricReading => $this->reading($m, $window, $baseline), $this->metrics);
        } catch (SourceUnavailable $e) {
            return new MetricsContext([], true, in_array($e->getMessage(), self::TOKENS, true) ? $e->getMessage() : 'unreachable');
        } catch (Throwable) {
            return new MetricsContext([], true, 'bad_response');
        }

        return new MetricsContext($readings);
    }

    private function reading(MetricDefinition $m, Window $window, Window $baseline): MetricReading
    {
        $query = $m->render($window, $baseline);
        if ($m->mode === 'range') {
            return $this->fromRange($m, $query, $window, $baseline);
        }
        $cur = $this->source->instant($query, $window->end);
        $base = $this->source->instant($query, $baseline->end);

        return $this->build($m, $m->aggregate, $cur->values, $cur->series, $base->values, $base->series, $cur->allowedLabelValues);
    }

    private function fromRange(MetricDefinition $m, string $query, Window $window, Window $baseline): MetricReading
    {
        $span = $window->end->getTimestamp() - $baseline->start->getTimestamp();
        $r = $this->source->range($query, $baseline->start, $window->end, max(60, (int) ceil($span / $this->maxPoints)));
        if ($r->series > 1) { // range is single series only (spec 009 R3)
            return new MetricReading($m->name, $m->unit, null, null, 'ambiguous', $r->series, []);
        }
        $cut = $baseline->end->getTimestamp();
        $cur = [];
        $base = [];
        foreach ($r->values as $i => $v) {
            if (($r->timestamps[$i] ?? $cut) < $cut) {
                $base[] = $v;
            } else {
                $cur[] = $v;
            }
        }

        return $this->build($m, $m->aggregate, $cur, $r->series, $base, $r->series, $r->allowedLabelValues);
    }

    /**
     * @param list<float>  $cur
     * @param list<float>  $base
     * @param list<string> $labels
     */
    private function build(MetricDefinition $m, ?string $aggregate, array $cur, int $curSeries, array $base, int $baseSeries, array $labels): MetricReading
    {
        if ($aggregate === null && max($curSeries, $baseSeries) > 1) {
            return new MetricReading($m->name, $m->unit, null, null, 'ambiguous', max($curSeries, $baseSeries), []);
        }
        $current = self::reduce($cur, $aggregate);
        $baseline = self::reduce($base, $aggregate);
        $state = match (true) {
            $current === null => 'no_data',
            $baseline === null || $baseline <= 0 => 'no_baseline',
            default => 'ok',
        };

        return new MetricReading($m->name, $m->unit, $current, $baseline, $state, $curSeries, $this->mask($labels));
    }

    /** @param list<float> $values */
    private static function reduce(array $values, ?string $aggregate): ?float
    {
        if ($values === []) {
            return null;
        }

        $v = match ($aggregate) {
            'sum' => array_sum($values),
            'max' => max($values),
            'avg' => array_sum($values) / count($values),
            default => $values[count($values) - 1], // 'last', or the single series
        };

        return is_finite($v) ? $v : null; // overflow is no data, never INF
    }

    /**
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private function mask(array $labels): array
    {
        $out = [];
        foreach (array_slice($labels, 0, 5) as $label) {
            // The line goes to chat: only a short plain token passes (no control, format or non-character code point, no markup, no space).
            if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $label) !== 1) {
                continue;
            }
            try {
                $masked = $this->normalizer->normalize($label);
            } catch (Throwable) {
                continue; // unparseable: dropped, never passed through
            }
            // layer 2 (Redactor) as well: a label that either layer would change is dropped, not shown masked
            if ($masked === $label && $this->redactor?->redactText($label, false) === $label) {
                $out[] = $masked;
            }
        }

        return $out;
    }
}
