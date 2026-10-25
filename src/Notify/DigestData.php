<?php

declare(strict_types=1);

namespace Watcher\Notify;

use DateTimeImmutable;
use Watcher\Analyze\Finding;
use Watcher\Analyze\Usage;
use Watcher\Baseline\Comparison;
use Watcher\Privacy\RedactedGroup;
use Watcher\Reduce\MiningStats;

/** Everything DigestRenderer needs; all strings are already redacted or bounded. */
final class DigestData
{
    /**
     * @param list<Finding>                $findings
     * @param array<string, RedactedGroup> $groups  by fingerprint (count and trend)
     * @param array<string, string>        $models  step => model id, for the footer
     * @param array<string, string>        $afterReleaseFingerprints fingerprint => release a NEW group was first seen after
     * @param int                          $undeclaredHostCount      call targets in templates that no declared dependency covers (never named)
     * @param list<string>                 $failedDependencies       declared dependencies whose source was unavailable
     * @param int                          $notAnalysed              groups triage flagged interesting that no analysis looked at
     * @param int                          $patternFailures          regex evaluations that failed (rules may have been skipped)
     * @param int                          $overflowedEvents         events folded into the per-level overflow group (source.group_cap hit)
     * @param array<string, array{log: ?string, code: list<string>}> $links by fingerprint; URLs built from config and redacted data only
     */
    public function __construct(
        public readonly string $service,
        public readonly string $environment,
        public readonly DateTimeImmutable $windowStart,
        public readonly DateTimeImmutable $windowEnd,
        public readonly string $timezone,
        public readonly array $findings,
        public readonly array $groups,
        public readonly ?string $release = null,
        public readonly ?Usage $usage = null,
        public readonly array $models = [],
        public readonly float $costUsd = 0.0,
        public readonly ?string $levelAnomalyLine = null,
        public readonly ?int $eventsReadTruncated = null,
        public readonly int $droppedGroups = 0,
        public readonly int $otherGroups = 0,
        public readonly int $otherEvents = 0,
        public readonly int $muted = 0,
        public readonly ?float $todayUsd = null,
        public readonly ?float $dailyBudgetUsd = null,
        public readonly ?int $todayTokens = null,
        public readonly ?int $dailyTokenLimit = null,
        public readonly array $afterReleaseFingerprints = [],
        public readonly int $undeclaredHostCount = 0,
        public readonly array $failedDependencies = [],
        public readonly array $links = [],
        public readonly int $overflowedEvents = 0,
        public readonly int $groupCap = 0,
        /** @var array<string, array{int, int, int}> kept groups partly matched by a field mute: fingerprint => [matched, events, mute index] */
        public readonly array $mutePartial = [],
        public readonly int $notAnalysed = 0,
        public readonly int $patternFailures = 0,
        /** @var int groups never sent to triage because it would not have fit the daily budget */
        public readonly int $notTriaged = 0,
        /** @var int escalations skipped (daily budget or analysis.max_input_tokens.escalate); the first-pass finding is shown as it is */
        public readonly int $escalationsSkipped = 0,
        /** @var DateTimeImmutable|null window end of the older snapshot used because the previous window's was missing (H6) */
        public readonly ?DateTimeImmutable $comparedWith = null,
        /** @var array<string, string> fingerprint => a code-built line appended to that finding (fleet correlation); never removes a finding */
        public readonly array $annotations = [],
        /** @var Comparison|null what this window was compared with (spec 004); null = the previous window, nothing to say */
        public readonly ?Comparison $comparison = null,
        /** @var MiningStats|null what the template miner gave up (spec 004 FR-010); counts only */
        public readonly ?MiningStats $mining = null,
        /** @var array{unknown_levels?: int, orphan_trace_lines?: int, message_tails_dropped?: int, truncated_lines?: int, regex_failures?: int, truncated_structured?: int} what the source's parser guessed or dropped (counts); empty = the source does not say */
        public readonly array $readDiagnostics = [],
        /** @var int groups served from an earlier verdict instead of being analysed again (counts only: reuse is never invisible) */
        public readonly int $reusedGroups = 0,
        /** @var int groups triage dropped as noise/known (low-level groups only) */
        public readonly int $triageDropped = 0,
        /** @var int error-or-higher / watch-rule groups triage tried to silence; they were analysed anyway */
        public readonly int $triageCoerced = 0,
        /** @var int error-or-higher / watch-rule groups the analysis reported nothing for; analysed again next window */
        public readonly int $unreported = 0,
        /** @var int new groups kept out of top_m by the per-run cap on new groups */
        public readonly int $newOverflow = 0,
        /** @var list<string> code-built lines about pod events (source unavailable, coverage); integers, times and fixed words only (spec 008) */
        public readonly array $podEventLines = [],
    ) {
    }
}
