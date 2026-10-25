<?php

declare(strict_types=1);

namespace Watcher\Metrics;

use InvalidArgumentException;
use Watcher\Schedule\Window;

/** One declared metric (config). The query text stays private: only the name is ever shown or put in an error. */
final class MetricDefinition
{
    public const AGGREGATES = ['sum', 'max', 'avg', 'last'];

    /**
     * @param array{relative: float, floor: float}|null $threshold null = context line only, never an anomaly
     */
    public function __construct(
        public readonly string $name,
        private readonly QueryTemplate $query,
        public readonly ?string $aggregate = null,
        public readonly ?array $threshold = null,
        public readonly string $unit = '',
        public readonly string $mode = 'instant',
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Metric name must match ^[a-z][a-z0-9_]{0,39}$.');
        }
        if ($aggregate !== null && !in_array($aggregate, self::AGGREGATES, true)) {
            throw new InvalidArgumentException("Metric {$name}: aggregate must be sum, max, avg or last.");
        }
        if (preg_match('/^[A-Za-z%\/ ]{0,8}$/D', $unit) !== 1) {
            throw new InvalidArgumentException("Metric {$name}: unit must be at most 8 of letters, %, / or space.");
        }
        if (!in_array($mode, ['instant', 'range'], true)) {
            throw new InvalidArgumentException("Metric {$name}: mode must be instant or range.");
        }
        if ($threshold !== null && ($threshold['relative'] <= 0 || $threshold['floor'] < 0)) {
            throw new InvalidArgumentException("Metric {$name}: threshold needs relative > 0 and floor >= 0.");
        }
    }

    public function render(Window $window, Window $baseline): string
    {
        return $this->query->render($window, $baseline);
    }
}
