<?php

declare(strict_types=1);

namespace Watcher\Source;

use DateTimeImmutable;
use Generator;
use LogicException;
use Psr\Clock\ClockInterface;
use Watcher\Level;
use Watcher\Reduce\Normalizer;
use Watcher\Schedule\Window;
use Watcher\Source\Kubernetes\Coverage;
use Watcher\Source\Kubernetes\EventLedger;
use Watcher\Source\Kubernetes\EventMapper;
use Watcher\Source\Kubernetes\EventMasker;
use Watcher\Source\Kubernetes\KubeApi;
use Watcher\Source\Kubernetes\KubeSettings;
use Watcher\Source\Kubernetes\LedgerEntry;
use Watcher\Source\Kubernetes\MappedRead;
use Watcher\State\Keys;
use Watcher\Source\Kubernetes\ReasonLevels;
use Watcher\State\StateStore;

/**
 * Pod-level problems from the Kubernetes API as a LogSource (spec 008): one LogEvent per deduplicated event, weighted by
 * its occurrences in the window. Cluster events live about an hour, so every read (the hourly triggers and the daily run)
 * first merges what the cluster still has into the per-service ledger, then the window is replayed from the ledger.
 *
 * The state store is bound by the Watcher once its final form is known (a dry run swallows writes there).
 * `versions()` is [] by contract: an event has no per-line version label. The stream selector is ignored: scope is
 * `kubernetes.namespaces` and `kubernetes.label_selector`.
 */
final class KubernetesEventsSource implements LogSource, ReadDiagnostics
{
    private const REUSE_SECONDS = 60;

    private ?StateStore $state = null;
    private ?EventLedger $ledger = null;
    private ?int $ingestedAt = null;
    private bool $truncated = false;
    private bool $clusterTruncated = false;
    private bool $loaded = false;
    private ?string $ingestFailure = null;
    private ?Window $lastWindow = null;
    /** @var array{events: int, observations: int, dropped: int, unattributed: int} */
    private array $stats = ['events' => 0, 'observations' => 0, 'dropped' => 0, 'unattributed' => 0];
    private readonly ReasonLevels $levels;
    private readonly EventMapper $mapper;

    /**
     * @param int $windowSeconds   length of the digest window (sizes the ledger's retention)
     * @param int $retentionCap    upper bound for the ledger's expiry (state.retention)
     */
    public function __construct(
        private readonly KubeApi $api,
        private readonly KubeSettings $settings,
        private readonly ClockInterface $clock,
        Normalizer $normalizer,
        private readonly string $stateKey,
        private readonly int $windowSeconds,
        private readonly int $retentionCap,
    ) {
        $this->levels = new ReasonLevels($settings->criticalReasons, $settings->minRepeatForProbeFailures);
        $this->mapper = new EventMapper($settings, new EventMasker($settings->namespaces, $settings->allowRegistries, $settings->allowImages), $normalizer, $stateKey);
    }

    /** @var (\Closure(): ?string)|null the run guard: why the run must stop reading (deadline, memory), or null */
    private ?\Closure $stop = null;

    /** The run's deadline and memory guard (set by EventsRun): no cluster request starts once it names a reason. */
    public function stopWhen(?\Closure $stop): void
    {
        $this->stop = $stop;
    }

    /** Called once by the Watcher, with the store it will really use (a DryRunStateStore in a dry run). */
    public function bindState(StateStore $state): void
    {
        $this->state = $state;
        $this->ledger = null;
        $this->ingestedAt = null;
        $this->loaded = false;
    }

    /** Read the cluster now and merge it into the ledger. Safe to repeat: only increases of a count are added. */
    public function ingest(): void
    {
        $ledger = $this->ledger();
        $this->api->beginBudget($this->stop);
        $now = $this->clock->now();
        $events = [];
        $pods = [];
        $this->clusterTruncated = false;
        foreach ($this->settings->namespaces as $ns) {
            $events = [...$events, ...$this->api->listEvents($ns)];
            $this->clusterTruncated = $this->api->lastTruncated() || $this->clusterTruncated;
            // The whole namespace: the selector is applied in code, and knowing every pod tells another service's pods from gone ones.
            $pods = [...$pods, ...$this->api->listPods($ns, null)];
            $this->clusterTruncated = $this->api->lastTruncated() || $this->clusterTruncated;
        }
        $read = $this->mapper->map($events, $pods, $now, $this->stop);
        $ledger->load();
        $this->loaded = true;
        $merged = $this->mergeLocked($ledger, $read, $now);
        // A trigger that lost the ledger lock merged nothing and holds a record that may predate the winner's write: reload what is stored
        // and say so, so a digest built from it carries a visible "ledger busy" instead of silently missing that read's events.
        $this->ingestFailure = $merged ? null : 'ledger busy';
        if (!$merged) {
            $ledger->load();
        }
        $this->ingestedAt = $now->getTimestamp();
        $this->stats = ['events' => count($events), 'observations' => count($read->observations), 'dropped' => $read->dropped, 'unattributed' => count($read->unattributed)];
    }

    /**
     * Triggers run outside the run lock and may overlap. The merge is load, add, save of one record: a short lock keeps one writer's
     * update from erasing the other's. A trigger that does not get it merges nothing; the winner read the same cluster.
     */
    private function mergeLocked(EventLedger $ledger, MappedRead $read, DateTimeImmutable $now): bool
    {
        $state = $this->state ?? throw new LogicException('KubernetesEventsSource needs bindState() before it is read');
        $key = Keys::lock($this->stateKey, 'events');
        $owner = bin2hex(random_bytes(8));
        if (!$state->acquire($key, $owner, 60)) {
            return false;
        }
        try {
            $ledger->load(); // what the previous holder wrote
            $ledger->merge($read, $now);
            $ledger->save();
        } finally {
            $state->release($key, $owner);
        }

        return true;
    }

    /** The reason class when the last fresh read failed but the hourly ledger was replayed anyway; null when the read worked. */
    public function ingestFailure(): ?string
    {
        return $this->ingestFailure;
    }

    /** @return array{events: int, observations: int, dropped: int, unattributed: int} counters of the last ingest */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * `check`: can the account list events and pods in every configured namespace? Reads nothing into the ledger.
     *
     * @return array<string, string> namespace => reason class ('' when fine)
     */
    public function probe(): array
    {
        $out = [];
        foreach ($this->settings->namespaces as $ns) {
            try {
                $this->api->probe($ns);
                $out[$ns] = '';
            } catch (SourceUnavailable $e) {
                $out[$ns] = str_starts_with($e->getMessage(), 'kubernetes ') ? substr($e->getMessage(), 11) : 'unavailable';
            }
        }

        return $out;
    }

    /** From the ledger as it stands: never reads the cluster, so a note about missing events cannot vanish with a failing read. */
    public function coverage(Window $window): Coverage
    {
        return $this->ledger()->coverage($window);
    }

    public function totals(Window $window, StreamSelector $selector): array
    {
        $this->fresh();
        $totals = [];
        $entries = $this->ledger()->window($window);
        $levels = $this->levels($entries);
        foreach ($entries as $i => $entry) {
            $name = strtolower($levels[$i]->name);
            $totals[$name] = ($totals[$name] ?? 0) + $entry->count;
        }

        return $totals;
    }

    /**
     * Counts only, like every other source's: what the ledger may have missed for the last window read, and whether the latest
     * cluster read failed (then only earlier reads are reported). The digest prints them in its read-quality line.
     *
     * @return array{unknown_levels: int, orphan_trace_lines: int, message_tails_dropped: int, k8s_read_failed: int, k8s_gaps: int, k8s_unattributed: int, k8s_record_full: int}
     */
    public function readDiagnostics(): array
    {
        $c = $this->lastWindow === null ? null : $this->ledger()->coverage($this->lastWindow);

        return [
            'unknown_levels' => 0,
            'orphan_trace_lines' => 0,
            'message_tails_dropped' => 0,
            'k8s_read_failed' => $this->ingestFailure === null ? 0 : 1,
            'k8s_gaps' => $c === null ? 0 : count($c->gaps),
            'k8s_unattributed' => $c === null ? 0 : $c->unattributed,
            'k8s_record_full' => $c === null ? 0 : ($c->overflow > 0 ? $c->overflow : ($c->capped ? 1 : 0)),
        ];
    }

    public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): Generator
    {
        $this->lastWindow = $window;
        $this->truncated = false;
        $this->fresh();
        $entries = $this->ledger()->window($window);
        usort($entries, static fn (LedgerEntry $a, LedgerEntry $b): int => [$a->last, $a->reason, $a->text, $a->podHash] <=> [$b->last, $b->reason, $b->text, $b->podHash]);
        $kept = 0;
        $levels = $this->levels($entries);
        foreach ($entries as $i => $entry) {
            $level = $levels[$i];
            $message = $entry->reason . ': ' . $entry->text;
            if (!$level->atLeast($filter->minLevel) && !$filter->matchesWatch($message, $entry->reason)) {
                continue;
            }
            if ($kept >= $filter->eventCap) {
                $this->truncated = true;

                return;
            }
            ++$kept;
            yield new LogEvent(
                $entry->last,
                $level,
                $message,
                $entry->reason,
                [],
                [],
                ['source' => 'k8s-events', 'workload_kind' => $entry->workloadKind, 'workload' => $entry->workload],
                $entry->count,
                $entry->podHash,
                LogEvent::ORIGIN_KUBERNETES,
                $entry->workloadKind,
            );
        }
        $this->truncated = $this->clusterTruncated;
    }

    public function lastReadTruncated(): bool
    {
        return $this->truncated;
    }

    public function versions(Window $window, StreamSelector $selector, string $label): array
    {
        return [];
    }

    /**
     * One level per entry, decided over the whole window: pods that share a message must not land in different groups
     * (and the group's fingerprint must not flip) just because one pod crossed the repeat threshold of a probe failure.
     *
     * @param list<LedgerEntry> $entries
     * @return array<int, Level>
     */
    private function levels(array $entries): array
    {
        $count = [];
        $restarted = [];
        foreach ($entries as $e) {
            $k = $e->reason . "\0" . $e->workloadKind . "\0" . $e->workload . "\0" . $e->text;
            $count[$k] = ($count[$k] ?? 0) + $e->count;
            $restarted[$k] = ($restarted[$k] ?? false) || $e->restarted;
        }
        $out = [];
        foreach ($entries as $i => $e) {
            $k = $e->reason . "\0" . $e->workloadKind . "\0" . $e->workload . "\0" . $e->text;
            $out[$i] = $this->levels->level($e->type, $e->reason, $count[$k], $restarted[$k]);
        }

        return $out;
    }

    /** At most one cluster read per minute: totals() and several lines() calls of one run share it. */
    private function fresh(): void
    {
        $this->ledger(); // fails before any request when the state is not bound
        if ($this->ingestedAt === null || $this->clock->now()->getTimestamp() - $this->ingestedAt >= self::REUSE_SECONDS) {
            try {
                $this->ingest(); // sets ingestFailure itself (a lost ledger lock)
            } catch (SourceUnavailable $e) {
                // FR-010 is "ledger plus a fresh read": a failed fresh read must not throw away the day the hourly reads collected.
                if (!$this->loaded) {
                    $this->ledger()->load();
                    $this->loaded = true;
                }
                if (!$this->ledger()->hasData()) {
                    throw $e;
                }
                $this->ingestFailure = str_starts_with($e->getMessage(), 'kubernetes ') ? substr($e->getMessage(), 11) : 'unavailable';
                $this->ingestedAt = $this->clock->now()->getTimestamp(); // do not retry within the minute: totals() and lines() share this
            }
        }
    }

    private function ledger(): EventLedger
    {
        if ($this->state === null) {
            throw new LogicException('KubernetesEventsSource needs bindState() before it is read');
        }

        return $this->ledger ??= new EventLedger(
            $this->state,
            $this->stateKey,
            $this->windowSeconds,
            $this->settings->tolerance,
            $this->settings->eventTtl,
            min($this->retentionCap, $this->settings->ledgerTtl ?? EventLedger::keepSeconds($this->windowSeconds, $this->settings->tolerance)),
        );
    }
}
