<?php

declare(strict_types=1);

namespace Watcher\Context;

use Watcher\Level;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\Reducer;
use Watcher\Schedule\Window;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\State\Keys;
use Watcher\State\StateStore;

/** FR-039: one totals() per declared dependency every run; lines() only for the deps the plan names. Read-only. */
final class DependencyContextReader
{
    public const TOP = 5;

    public function __construct(
        private readonly Normalizer $normalizer,
        private readonly Redactor $redactor,
        private readonly StateStore $store,
        private readonly int $retention,
        /** The SERVICE's `redaction.min_group_events_for_text`: a dependency's text is never less protected. */
        private readonly int $minGroupEventsForText,
    ) {
    }

    /**
     * @param array<string, array{stream_tags: array<string, string>, source?: ?string}> $declared
     * @param callable(string): LogSource                                                $sourceFor dep name => source
     * @param array<string, array<string, int>|null>                                     $previousTotals from the previous dep snapshots
     * @param array<string, array<string, int>>                                          $currentTotals  totals the caller already queried (not asked for again)
     */
    public function read(
        string $service,
        array $declared,
        callable $sourceFor,
        DependencyPlan $plan,
        Window $window,
        int $readCap,
        array $previousTotals = [],
        array $currentTotals = [],
    ): DependencyReadResult {
        $contexts = [];
        $failed = [];
        $windowEnd = $window->end->format('Y-m-d\TH:i:s\Z');

        foreach ($declared as $name => $dep) {
            $name = (string) $name;
            $source = $sourceFor($name);
            $selector = new StreamSelector($dep['stream_tags']);
            $prev = $previousTotals[$name] ?? null;
            $first = $plan->firstSighting[$name] ?? ($prev === null);
            $refs = $plan->referencedBy[$name] ?? [];

            try {
                $totals = $currentTotals[$name] ?? $source->totals($window, $selector);
                $top = [];
                $counts = [];
                if (in_array($name, $plan->linesFor, true)) {
                    $filter = new ReadFilter(Level::Error, [], $readCap);
                    $groups = (new Reducer($this->normalizer, $this->minGroupEventsForText))
                        ->reduce($source->lines($window, $selector, $filter));
                    foreach ($groups as $g) {
                        $counts[$g->fingerprint] = $g->count;
                    }
                    foreach (array_slice($groups, 0, self::TOP) as $g) {
                        $top[] = $this->redactor->redact($g);
                    }
                }
            } catch (SourceUnavailable) {
                $failed[] = $name;
                $contexts[] = new DependencyContext($name, true, [], $prev, [], $refs, $first, true);
                continue;
            }

            $this->store->put(
                Keys::dependencySnapshot($service, $name, $windowEnd),
                ['totals' => $totals, 'groups' => $counts],
                $this->retention,
            );
            $contexts[] = new DependencyContext($name, true, $totals, $prev, $top, $refs, $first);
        }

        return new DependencyReadResult($contexts, $failed);
    }
}
