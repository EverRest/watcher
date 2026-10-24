<?php

declare(strict_types=1);

namespace Watcher\Operate;

use Composer\InstalledVersions;
use DateTimeInterface;
use Throwable;

/** The one-line JSON summary of a run: counts, outcome names, numbers and times only (Principle I). */
final class JsonSummary
{
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

    public static function packageVersion(string $package): string
    {
        try {
            return InstalledVersions::getPrettyVersion($package) ?? 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
