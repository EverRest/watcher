<?php

declare(strict_types=1);

namespace Watcher\Operate;

use InvalidArgumentException;
use Watcher\Analyze\Usage;

/** Counters of one run, for the JSON summary. Numbers only: nothing here can hold log text or a field value. */
final class RunStats
{
    public int $eventsRead = 0;

    public int $groups = 0;

    /** Events the Normalizer could not scan (masked, counted) and lines dropped above 64 KB. */
    public int $unscannableEvents = 0;

    public int $oversizedLines = 0;

    /** State entries that could not be decoded and were read as missing (Redis store). */
    public int $stateUnreadable = 0;

    /** Events folded into the per-level overflow group (source.group_cap hit). */
    public int $overflowedEvents = 0;

    /** Regex evaluations that failed and failed open (rules, mutes, backend filters). */
    public int $patternFailures = 0;

    /** @var array<string, int> what the source's parser guessed or dropped (ReadDiagnostics); only the names JsonSummary::READ_QUALITY are exported */
    public array $readQuality = [];

    public int $candidates = 0;

    public int $findings = 0;

    /** @var array{triage: int, analyze: int, escalate: int} */
    public array $calls = ['triage' => 0, 'analyze' => 0, 'escalate' => 0];

    public int $tokensIn = 0;

    public int $tokensOut = 0;

    public int $tokensCached = 0;

    public float $costUsd = 0.0;

    public ?int $durationMs = null;

    /** @var array{status: string, read: int, deduplicated: int, unattributed: int, truncated: bool}|null pod events (spec 008); null = not configured */
    public ?array $events = null;

    /** Source pages fetched, when the source reports it. */
    public ?int $pages = null;

    /** @param string $step 'triage'|'analyze'|'escalate'; anything else is refused */
    public function addUsage(string $step, Usage $usage): void
    {
        if (! isset($this->calls[$step])) {
            throw new InvalidArgumentException('Unknown step');
        }
        $this->calls[$step]++;
        $this->tokensIn += $usage->inputTokens;
        $this->tokensOut += $usage->outputTokens;
        $this->tokensCached += $usage->cacheReadTokens;
        $this->costUsd += $usage->costUsd;
    }
}
