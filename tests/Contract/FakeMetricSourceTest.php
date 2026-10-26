<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Watcher\Metrics\MetricResult;
use Watcher\Metrics\MetricSource;
use Watcher\Tests\Fakes\FakeMetricSource;

final class FakeMetricSourceTest extends MetricSourceContract
{
    protected function makeSourceWithSamples(array $raw): MetricSource
    {
        $values = array_map('floatval', array_values(array_filter($raw, 'is_numeric')));
        return FakeMetricSource::always(new MetricResult($values, count($raw)));
    }

    protected function makeSourceFailing(int $httpStatus): MetricSource
    {
        return FakeMetricSource::failing(match ($httpStatus) {
            401, 403 => 'unauthorized',
            429 => 'rate_limited',
            0 => 'unreachable',
            default => 'unreachable',
        });
    }

    protected function secret(): string
    {
        return 'tok-s3cret';
    }
}
