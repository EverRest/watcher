<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Watcher\Operate\HeartbeatStatus;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunSummaryData;

/**
 * Last step of a run (spec 013): builds the events, hands them to the sinks once and keeps the counters.
 * Fails open: nothing here can throw into the run, and a sink can neither change the outcome nor outlast the run deadline.
 */
final class RunPublisher
{
    public function __construct(
        private readonly SinkDispatcher $sinks,
        private readonly ObserveSettings $settings,
        private readonly ClockInterface $clock,
        private readonly ?ObserveCounters $counters,
        private readonly ?LoggerInterface $log,
    ) {
    }

    /**
     * @param int|null $remaining seconds left of the run deadline (RunGuard), null = no deadline
     * @param callable(string): void $out where a dry run prints the events
     *
     * @return array{run_id: string, failures: array<string, int>}|null null when nothing was flushed
     */
    public function publish(RunObservation $obs, RunSummaryData $d, ?HeartbeatStatus $heartbeat, ?int $remaining, bool $dryRun, callable $out): ?array
    {
        try {
            if (!$dryRun && $this->counters !== null) {
                $obs->priorSinkFailures = $this->counters->unseenSinkFailures();
            }
            $this->configWarnings($obs);
            $events = (new EventFactory($this->clock, JsonSummary::packageVersion('everrest/watcher'), $this->settings->topGroups))->build($obs, $d, $heartbeat);
            if ($events === []) {
                return null;
            }
            if ($dryRun) {
                $out($this->sinks->render($events)); // no sink is called, no counter is stored

                return null;
            }
            $this->counters?->countRun($d->outcome->value);
            foreach ($obs->deliveryFailures as $failure) {
                $this->counters?->countDelivery($failure['channel']);
            }
            $failures = $this->sinks->dispatch($events, $remaining);
            $this->counters?->countSinkFailures($failures);
            $this->counters?->markClean(array_replace(array_fill_keys($this->enabled(), false), array_map(static fn (int $n): bool => $n > 0, $failures)));

            return ['run_id' => (string) ($events[0]->fields['run_id'] ?? ''), 'failures' => $failures];
        } catch (Throwable $e) {
            try {
                $this->log?->warning('observe publish failed: ' . $e::class);
            } catch (Throwable) {
                // a broken logger must not fail the run
            }

            return null;
        }
    }

    private function configWarnings(RunObservation $obs): void
    {
        $h = $this->settings->http;
        if ($h['enabled'] && $h['include_text']) {
            $obs->warn('include_text_external', 'observe.http.include_text');
        }
        if ($h['enabled'] && $h['allow_insecure']) {
            $obs->warn('http_insecure_allowed', 'observe.http.allow_insecure');
        }
    }

    /** @return list<string> */
    private function enabled(): array
    {
        $s = $this->settings;

        return array_keys(array_filter(['log' => $s->log['enabled'], 'file' => $s->file['enabled'], 'http' => $s->http['enabled'], 'prometheus' => $s->prometheus['enabled']]));
    }
}
