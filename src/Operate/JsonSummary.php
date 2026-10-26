<?php

declare(strict_types=1);

namespace Watcher\Operate;

use Composer\InstalledVersions;
use DateTimeInterface;
use Throwable;

/** The one-line JSON summary of a run: counts, outcome names, numbers and times only (Principle I). */
final class JsonSummary
{
    /** The names under `read_quality`: fixed, each always present (0 when the source did not report it). */
    public const READ_QUALITY = [
        'unknown_levels', 'orphan_trace_lines', 'message_tails_dropped', 'truncated_lines', 'truncated_structured', 'regex_failures', 'pages_shrunk',
    ];

    private const PACKAGE = 'everrest/watcher';

    /** Letters, digits and a little punctuation, short: a code or "internal error (Class)", never prose with data. */
    private const REASON = '/^[A-Za-z0-9_.:()\\\\ -]{1,80}$/';

    public function render(RunSummaryData $d): string
    {
        $s = $d->stats;
        $reason = self::reason($d->reason);

        return json_encode([
            'schema' => 1,
            'service' => $d->service,
            ...($d->environment === null ? [] : ['environment' => $d->environment]),
            'outcome' => $d->outcome->value,
            'reason' => $reason,
            'window_start' => $d->windowStart?->format(DateTimeInterface::ATOM),
            'window_end' => $d->windowEnd?->format(DateTimeInterface::ATOM),
            'duration_ms' => $s?->durationMs,
            'events_read' => $s?->eventsRead,
            'groups' => $s?->groups,
            'candidates' => $s?->candidates,
            'findings' => $s?->findings,
            'calls' => $s?->calls,
            'tokens_in' => $s->tokensIn ?? 0,
            'tokens_out' => $s->tokensOut ?? 0,
            'tokens_cached' => $s->tokensCached ?? 0,
            'cost_usd' => $s->costUsd ?? 0.0,
            'today_usd' => $d->todayUsd,
            'release' => $d->release,
            'version' => self::packageVersion(self::PACKAGE),
            'dry_run' => $d->dryRun,
            'state_unreadable' => $s->stateUnreadable ?? 0,
            'unscannable_events' => $s->unscannableEvents ?? 0,
            'oversized_lines' => $s->oversizedLines ?? 0,
            'overflowed_events' => $s->overflowedEvents ?? 0,
            'pattern_failures' => $s->patternFailures ?? 0,
            'read_quality' => array_combine(self::READ_QUALITY, array_map(static fn (string $k): int => $s->readQuality[$k] ?? 0, self::READ_QUALITY)),
            ...($s?->events === null ? [] : ['events' => $s->events]),
            ...($d->runId() === null ? [] : ['run_id' => $d->runId(), 'sink_failures' => (object) $d->sinkFailures()]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** A short code passes; anything else becomes 'unspecified'. */
    public static function reason(?string $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return preg_match(self::REASON, $reason) === 1 && preg_match('/[\w-]{24,}/', $reason) !== 1 ? $reason : 'unspecified';
    }

    /**
     * Package version for digests and observe events. Must fit the event schema
     * (`^[A-Za-z0-9._+-]{1,32}$`): Composer branch installs can be longer
     * (e.g. `dev-fix-review-batch2-state-slack`).
     */
    public static function packageVersion(string $package): string
    {
        try {
            $raw = InstalledVersions::getPrettyVersion($package) ?? 'unknown';
        } catch (Throwable) {
            $raw = 'unknown';
        }
        $safe = preg_replace('/[^A-Za-z0-9._+-]/', '-', $raw) ?? 'unknown';
        $safe = trim($safe, '.-+_') ?: 'unknown';

        return substr($safe, 0, 32);
    }
}
