<?php

declare(strict_types=1);

namespace Watcher\Events;

use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;
use Watcher\Config\WatchConfig;
use Watcher\Schedule\RunGuard;
use Watcher\Schedule\Window;
use Watcher\Source\KubernetesEventsSource;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\State\StateStore;

/**
 * Everything the Watcher needs for pod events (spec 008), kept out of Watcher.php: the hourly ledger read on every trigger,
 * the events stream merged after the log stream, the notice when the cluster could not be read, the coverage note and the
 * counters for the JSON summary. Null when the service has no Kubernetes source, so a service without one pays nothing.
 */
final class EventsRun
{
    private ?string $failure = null;
    private ?string $stale = null;
    private ?string $ingestFailure = null;
    private bool $truncated = false;
    private int $deduplicated = 0;
    private bool $read = false;

    /** @param list<KubernetesEventsSource> $ingesting every Kubernetes source of the service (the extra one and a main one) */
    private function __construct(
        private readonly ?KubernetesEventsSource $extra,
        private readonly array $ingesting,
        private readonly string $service,
        private readonly ?LoggerInterface $log,
    ) {
    }

    /** Binds the final state store to each Kubernetes source (a dry run's store swallows writes there). */
    public static function create(WatchConfig $config, LogSource $main, ?KubernetesEventsSource $extra, StateStore $state, ?LoggerInterface $log): ?self
    {
        $extra = $extra !== null && $config->on('kubernetes') && $config->kubernetes?->events === true ? $extra : null;
        $ingesting = array_values(array_filter([$extra, $main instanceof KubernetesEventsSource ? $main : null]));
        if ($ingesting === []) {
            return null;
        }
        foreach ($ingesting as $source) {
            $source->bindState($state);
        }

        return new self($extra, $ingesting, $config->stateKey, $log);
    }

    /** The run's guard bounds every cluster read of this run, the one before the run gate included. */
    public function bindGuard(?RunGuard $guard): void
    {
        foreach ($this->ingesting as $source) {
            $source->stopWhen($guard === null ? null : static fn (): ?string => $guard->reason());
        }
    }

    /** Every trigger, due or not: merge what the cluster still holds (it keeps events about an hour). Never fails the trigger. */
    public function ingestOnTrigger(): void
    {
        $this->ingestFailure = null;
        foreach ($this->ingesting as $source) {
            try {
                $source->ingest();
                if (($busy = $source->ingestFailure()) !== null) {
                    $this->ingestFailure ??= 'k8s_ingest_failed:' . str_replace(' ', '_', $busy);
                }
            } catch (Throwable $e) {
                // Kept for the heartbeat even with no logger: a permanently unreadable credential must show in `watcher status`.
                $code = $e instanceof SourceUnavailable && str_starts_with($e->getMessage(), 'kubernetes ') ? substr($e->getMessage(), 11) : 'unavailable';
                $this->ingestFailure ??= 'k8s_ingest_failed:' . str_replace(' ', '_', $code);
                // A fixed vocabulary for the cluster's own failures; a class name for anything else. No digest exists here to carry it.
                $this->log?->warning('kubernetes events were not read', ['service' => $this->service, 'reason' => $e instanceof SourceUnavailable ? $e->getMessage() : $e::class]);
            }
        }
    }

    /**
     * The final state of the events read for THIS run, as the run code: a read that failed at any stage (the ledger read at the trigger,
     * the re-read inside the run, the replay) is `k8s_ingest_failed:<reason>`; one that worked clears an earlier blip of the trigger.
     * Before the run reached the events stream only the trigger's own outcome is known.
     */
    public function runCode(): ?string
    {
        if ($this->read) {
            $why = $this->failure ?? $this->stale;

            return $why === null ? null : 'k8s_ingest_failed:' . str_replace(' ', '_', $why);
        }

        return $this->ingestFailure;
    }

    /** `k8s_ingest_failed:<code>` when this trigger's ledger read failed, else null. */
    public function ingestFailure(): ?string
    {
        return $this->ingestFailure;
    }

    /**
     * The extra source's events for the window. A failure is remembered and yields nothing (the log part is unaffected).
     *
     * @return iterable<\Watcher\Source\LogEvent>
     */
    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): iterable
    {
        if ($this->extra === null) {
            return;
        }
        $this->read = true;
        try {
            foreach ($this->extra->lines($window, $selector, $filter) as $event) {
                $this->deduplicated += $event->occurrences;
                yield $event;
            }
            $this->truncated = $this->extra->lastReadTruncated();
            $this->stale = $this->extra->ingestFailure();
        } catch (Throwable $e) {
            // Anything the events path throws (a state fault, a client fault) must not cost the log part its digest (FR-013).
            $this->failure = $e instanceof SourceUnavailable && str_starts_with($e->getMessage(), 'kubernetes ') ? substr($e->getMessage(), 11) : 'unavailable';
        }
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    /** The reason class when the cluster could not be read in this run, else null. */
    public function failure(): ?string
    {
        return $this->failure;
    }

    /**
     * The plain notice for a run that has no digest to carry it; names the service, like every other notice.
     *
     * @return string|null
     */
    public function problemNotice(string $environment = ''): ?string
    {
        $who = 'watcher · ' . $this->service . ($environment === '' ? '' : ' · ' . $environment) . ' — ';
        if ($this->failure !== null) {
            return '⚠️ ' . $who . 'Pod events were not read: ' . $this->failure . '.';
        }

        return $this->stale === null ? null : '⚠️ ' . $who . 'Pod events: the latest read failed (' . $this->stale . '); only what earlier reads collected is reported.';
    }

    /** @return list<string> lines for the digest: the failure, or the coverage note when events may be missing */
    public function digestLines(Window $window, DateTimeZone $tz): array
    {
        if ($this->failure !== null) {
            return ['⚠️ Pod events were not read: ' . $this->failure . '.'];
        }
        if ($this->extra === null || !$this->read) {
            return [];
        }
        $stale = $this->stale === null ? [] : ['⚠️ Pod events: the latest read failed (' . $this->stale . '); only what earlier reads collected is reported.'];
        try {
            $c = $this->extra->coverage($window);
        } catch (Throwable) {
            return [];
        }
        if ($c->isComplete()) {
            return $stale;
        }
        $parts = [];
        foreach (array_slice($c->gaps, 0, 3) as [$from, $to]) {
            $parts[] = sprintf('possible gap %s-%s (no read within the cluster\'s event retention)', $from->setTimezone($tz)->format('m-d H:i'), $to->setTimezone($tz)->format('m-d H:i'));
        }
        if (count($c->gaps) > 3) {
            $parts[] = (count($c->gaps) - 3) . ' more gaps';
        }
        if ($c->shortHistory && $c->since !== null) {
            $parts[] = 'events were read only since ' . $c->since->setTimezone($tz)->format('m-d H:i');
        }
        if ($c->unattributed > 0) {
            $parts[] = number_format($c->unattributed) . ' events matched no selected workload';
        }
        if ($c->capped) {
            $parts[] = 'the event record was full: the oldest events were dropped' . ($c->overflow > 0 ? ' (' . number_format($c->overflow) . ' entries, ledger_overflow)' : '');
        }

        return [...$stale, 'ℹ️ Pod events: ' . implode('; ', $parts) . '.'];
    }

    /**
     * Numbers and fixed words for `--json` (spec 008 FR-017).
     *
     * @return array{status: string, read: int, deduplicated: int, unattributed: int, truncated: bool}
     */
    public function summary(Window $window): array
    {
        $stats = $this->extra?->stats() ?? ['events' => 0, 'unattributed' => 0];
        $c = $this->failure === null && $this->read && $this->extra !== null ? $this->extra->coverage($window) : null;

        return [
            'status' => $this->failure !== null ? 'unavailable' : ($this->stale !== null ? 'stale' : 'ok'),
            'read' => $stats['events'],
            'deduplicated' => $this->deduplicated,
            'unattributed' => $c->unattributed ?? 0,
            'truncated' => $this->truncated,
        ];
    }
}
