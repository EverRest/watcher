<?php

declare(strict_types=1);

namespace Watcher\Reduce;

use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Schedule\RunGuard;
use Watcher\Schedule\Window;
use Watcher\Select\Matcher;
use Watcher\Select\MatcherParser;
use Watcher\Select\WatchRules;
use Watcher\Source\LogEvent;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\StreamSelector;

/**
 * @internal Watcher's own collaborator, not part of the package API.
 *
 * Reads the window from the source and reduces it to problem groups. On the way it keeps the counters of the run:
 * events read, truncation, which watch rules and mute entries matched which fingerprint (names and counts only).
 */
final class EventCollector
{
    private int $eventsRead = 0;

    private bool $truncated = false;

    /** @var array<string, list<string>> rule names per fingerprint */
    private array $ruleHits = [];

    /** @var array<string, array<int, int>> fingerprint => [mute entry index => events that matched it] (counts only) */
    private array $muteHits = [];

    /** @var list<ReadFilter> kept only to read their patternErrors counters */
    private array $filters = [];

    /** @var array<int, Matcher> */
    private array $muteMatchers = [];

    private bool $ruleMatched = false; // set by observe for the event the reducer is processing right now (the stream is sequential)

    private readonly Fingerprinter $fingerprinter;

    private ?Reducer $reducer = null;

    public function __construct(
        private readonly WatchConfig $config,
        private readonly LogSource $source,
        private readonly Normalizer $normalizer,
        private readonly WatchRules $rules,
        private readonly ?RunGuard $guard = null,
    ) {
        foreach ($config->mute as $i => $entry) {
            if (!isset($entry['fingerprint'])) {
                $m = MatcherParser::parse($entry);
                if ($m->isFieldMatcher()) {
                    $this->muteMatchers[$i] = $m;
                }
            }
        }
        $this->fingerprinter = new Fingerprinter($normalizer);
    }

    /**
     * @param list<Level> $anomalies levels read first, each up to anomaly_cap
     *
     * @return list<ProblemGroup>
     */
    public function collect(Window $window, StreamSelector $selector, array $anomalies): array
    {
        $c = $this->config;
        // An event folded into the overflow group drops its hits: they would key a fingerprint that has no group, and a mute must never hide the overflow.
        $this->reducer = new Reducer(
            $this->normalizer,
            $c->minGroupEventsForText,
            $c->source['group_cap'],
            fn (): bool => $this->ruleMatched,
            function (LogEvent $e): void {
                $fp = $this->fingerprinter->of($e);
                unset($this->ruleHits[$fp], $this->muteHits[$fp]);
            },
            $c->grouping['similarity'],
            $c->grouping['message_cap'],
            groupBySimilarity: $c->on('grouping'),
            memoryBudgetBytes: $c->grouping['memory_budget_mb'] * 1048576,
        );

        return $this->reducer->reduce($this->stream($window, $selector, $anomalies));
    }

    public function eventsRead(): int
    {
        return $this->eventsRead;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    /** @return array<string, list<string>> */
    public function ruleHits(): array
    {
        return $this->ruleHits;
    }

    /** @return array<string, array<int, int>> */
    public function muteHits(): array
    {
        return $this->muteHits;
    }

    /** Counts only: a regex that failed to evaluate (backtrack limit) fails open, and the operator should hear about it. */
    public function patternFailures(): int
    {
        return array_sum(array_map(static fn (Matcher $m): int => $m->failures, $this->muteMatchers))
            + array_sum(array_map(static fn (ReadFilter $f): int => $f->patternErrors, $this->filters));
    }

    /** Events the reducer could not scan (counts only). */
    public function unscannableEvents(): int
    {
        return $this->reducer?->unscannableEvents() ?? 0;
    }

    /** Lines over the length limit that the read filters dropped. */
    public function oversizedLines(): int
    {
        return array_sum(array_map(static fn (ReadFilter $f): int => $f->oversizedLines, $this->filters));
    }

    /** Why a source read stopped early (the first filter that says), or null. */
    public function truncationReason(): ?string
    {
        return array_values(array_filter(array_map(static fn (ReadFilter $f): ?string => $f->truncationReason, $this->filters)))[0] ?? null;
    }

    public function overflowedEvents(): int
    {
        return $this->reducer?->overflowedEvents() ?? 0;
    }

    public function mining(): MiningStats
    {
        return ($this->reducer ?? throw new \LogicException('mining() before collect()'))->mining();
    }

    /**
     * @param list<Level> $anomalies
     *
     * @return iterable<LogEvent>
     */
    private function stream(Window $window, StreamSelector $selector, array $anomalies): iterable
    {
        $stop = fn (): ?string => $this->guard?->reason();
        foreach ($anomalies as $level) {
            if ($stop() !== null) {
                $this->truncated = true;

                return;
            }
            $this->filters[] = $filter = new ReadFilter($level, [], $this->config->source['anomaly_cap']);
            $filter->stopWhen($stop);
            foreach ($this->source->lines($window, $selector, $filter) as $e) {
                if ($e->level === $level) {
                    yield $this->observe($e);
                }
            }
            $this->truncated = $this->source->lastReadTruncated() || $this->truncated;
        }
        if ($stop() !== null) {
            $this->truncated = true;

            return;
        }
        $this->filters[] = $filter = new ReadFilter($this->config->source['read_min_level'], $this->rules->backendPatterns(), $this->config->source['event_cap']);
        $filter->stopWhen($stop);
        foreach ($this->source->lines($window, $selector, $filter) as $e) {
            yield $this->observe($e);
        }
        $this->truncated = $this->source->lastReadTruncated() || $this->truncated;
    }

    /** Field VALUES are matched here and dropped: only rule names and mute indexes are kept per fingerprint. */
    private function observe(LogEvent $e): LogEvent
    {
        ++$this->eventsRead; // counted as they stream, so muted events are included
        $names = $this->rules->namesMatchingEvent($e);
        $this->ruleMatched = $names !== [];
        $hits = [];
        foreach ($this->muteMatchers as $i => $m) {
            if ($m->matchesEvent($e)) {
                $hits[] = (int) $i;
            }
        }
        if ($names !== [] || $hits !== []) {
            $fp = $this->fingerprinter->of($e);
            $this->ruleHits[$fp] = array_values(array_unique([...($this->ruleHits[$fp] ?? []), ...$names]));
            foreach ($hits as $i) {
                $this->muteHits[$fp][$i] = ($this->muteHits[$fp][$i] ?? 0) + 1;
            }
        }

        return $e;
    }
}
