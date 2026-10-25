<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Throwable;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/**
 * The few persisted counters a one-shot process needs (runs per outcome, sink failures, delivery failures) and the
 * "failed since the last clean flush" marker. Every key expires with state.retention. Label values are closed lists
 * (MetricCatalog); nothing here ever throws: a store that is down reads as unknown and writes are lost, never raised.
 */
final class ObserveCounters
{
    public function __construct(private readonly StateStore $store, private readonly string $stateKey, private readonly int $retention)
    {
    }

    public function countRun(string $outcome): void
    {
        if (in_array($outcome, MetricCatalog::OUTCOMES, true)) {
            $this->add(Keys::observeRuns($this->stateKey, $outcome));
        }
    }

    /** @param array<string, int> $failures sink => count */
    public function countSinkFailures(array $failures): void
    {
        foreach ($failures as $sink => $n) {
            if (in_array($sink, EventSchema::SINKS, true) && $n > 0) {
                $this->add(Keys::observeSinkFailures($this->stateKey, $sink), (float) $n);
            }
        }
    }

    public function countDelivery(string $channel): void
    {
        if (in_array($channel, ['slack', 'telegram', 'discord'], true)) {
            $this->add(Keys::observeDeliveryFailures($this->stateKey, $channel));
        }
    }

    /**
     * After a flush: a sink that was enabled and did not fail has now shown its events, so earlier failures are seen.
     *
     * @param array<string, bool> $failed enabled sink => whether it failed in this flush
     */
    public function markClean(array $failed): void
    {
        foreach ($failed as $sink => $didFail) {
            if ($didFail || !in_array($sink, EventSchema::SINKS, true)) {
                continue;
            }
            try {
                $total = $this->total(Keys::observeSinkFailures($this->stateKey, $sink));
                $this->store->put(Keys::observeSeen($this->stateKey, $sink), ['total' => $total], Keys::ttl(Keys::OBSSEEN, $this->retention, 0));
            } catch (Throwable) {
                // best effort
            }
        }
    }

    /** @return list<string> sinks with failures since their last clean flush (the heartbeat shows `sink_failed:<sink>`) */
    public function unseenSinkFailures(): array
    {
        $out = [];
        try {
            foreach (EventSchema::SINKS as $sink) {
                if ($this->total(Keys::observeSinkFailures($this->stateKey, $sink)) > $this->total(Keys::observeSeen($this->stateKey, $sink))) {
                    $out[] = $sink;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $out;
    }

    /** @return array{runs: array<string, float>, sink_failures: array<string, float>, delivery_failures: array<string, float>} empty sections when the store is unreadable */
    public function snapshot(): array
    {
        try {
            $read = fn (array $labels, callable $key): array => array_combine($labels, array_map(fn (string $l): float => $this->total($key($this->stateKey, $l)), $labels));

            return [
                'runs' => $read(MetricCatalog::OUTCOMES, Keys::observeRuns(...)),
                'sink_failures' => $read(EventSchema::SINKS, Keys::observeSinkFailures(...)),
                'delivery_failures' => $read(['slack', 'telegram', 'discord'], Keys::observeDeliveryFailures(...)),
            ];
        } catch (Throwable) {
            return ['runs' => [], 'sink_failures' => [], 'delivery_failures' => []];
        }
    }

    private function total(string $key): float
    {
        $v = $this->store->get($key)['total'] ?? 0.0;

        return is_int($v) || is_float($v) ? (float) $v : 0.0;
    }

    private function add(string $key, float $by = 1.0): void
    {
        try {
            $this->store->increment($key, $by, Keys::ttl(Keys::OBSRUNS, $this->retention, 0));
        } catch (Throwable) {
            // a lost count is a Prometheus counter reset, not a failed run
        }
    }
}
