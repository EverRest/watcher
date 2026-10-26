<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Closure;
use DateTimeImmutable;
use Watcher\Metrics\MetricResult;
use Watcher\Metrics\MetricSource;
use Watcher\Source\SourceUnavailable;

/** Scripted MetricSource that records every call (SC-002 asserts the call count). */
final class FakeMetricSource implements MetricSource
{
    /** @var list<array{string, string, DateTimeImmutable, ?DateTimeImmutable}> kind, query, at/start, end */
    public array $calls = [];

    /**
     * @param (Closure(string, DateTimeImmutable): MetricResult)|null                              $instant
     * @param (Closure(string, DateTimeImmutable, DateTimeImmutable, int): MetricResult)|null      $range
     * @param string|null                                                                          $failWith failure token thrown by every call
     */
    public function __construct(private readonly ?Closure $instant = null, private readonly ?Closure $range = null, private readonly ?string $failWith = null)
    {
    }

    public static function failing(string $token): self
    {
        return new self(null, null, $token);
    }

    /** Same result for every instant call. */
    public static function always(MetricResult $result): self
    {
        return new self(static fn (): MetricResult => $result);
    }

    public function instant(string $query, DateTimeImmutable $at): MetricResult
    {
        $this->calls[] = ['instant', $query, $at, null];
        $this->failIfScripted();

        return $this->instant === null ? new MetricResult([], 0) : ($this->instant)($query, $at);
    }

    public function range(string $query, DateTimeImmutable $start, DateTimeImmutable $end, int $stepSeconds): MetricResult
    {
        $this->calls[] = ['range', $query, $start, $end];
        $this->failIfScripted();

        return $this->range === null ? new MetricResult([], 0) : ($this->range)($query, $start, $end, $stepSeconds);
    }

    private function failIfScripted(): void
    {
        if ($this->failWith !== null) {
            throw new SourceUnavailable($this->failWith);
        }
    }
}
