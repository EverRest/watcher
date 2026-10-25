<?php

declare(strict_types=1);

namespace Watcher;

use DateTimeImmutable;
use Watcher\Analyze\PayloadExtras;
use Watcher\Baseline\Comparison;
use Watcher\Context\DeployInfo;
use Watcher\Context\TraceIdCollector;
use Watcher\Notify\DigestExtras;
use Watcher\Reduce\EventCollector;
use Watcher\Reduce\ProblemGroup;
use Watcher\Schedule\Window;
use Watcher\Select\MuteResult;

/**
 * @internal Carrier between Watcher's own steps.
 *
 * What the first half of a run found out about one window: everything the analysis and the digest need, nothing they change. */
final class WindowRead
{
    /**
     * @param list<ProblemGroup> $allGroups    before mute filtering: what the snapshot keeps
     * @param array<string, int> $totals
     * @param list<Level>        $anomalies
     * @param array<string, int> $previousTotals
     */
    public function __construct(
        public readonly Window $window,
        public readonly array $allGroups,
        public readonly array $totals,
        public readonly array $anomalies,
        public readonly array $previousTotals,
        public readonly ?DateTimeImmutable $comparedWith,
        public readonly DeployInfo $deploy,
        public readonly EventCollector $collector,
        public readonly MuteResult $muteResult,
        public readonly Comparison $comparison,
        public readonly int $patternFailures,
        public readonly ?DigestExtras $extras = null,
        public readonly ?PayloadExtras $payloadExtras = null,
        public readonly ?TraceIdCollector $traces = null,
    ) {
    }
}
