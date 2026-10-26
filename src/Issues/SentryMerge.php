<?php

declare(strict_types=1);

namespace Watcher\Issues;

use Throwable;
use Watcher\Notify\DigestExtras;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;
use Watcher\Schedule\Window;
use Watcher\Source\SourceUnavailable;

/**
 * Reads the Sentry issues of one window and merges their groups with the log groups (spec 009 US3): two entries for one problem
 * stay two entries. Never throws: any failure becomes a fixed token, and the log groups come back unchanged (SC-006).
 */
final class SentryMerge
{
    public function __construct(
        private readonly IssueSource $source,
        private readonly int $maxIssues,
        private readonly int $minGroupEventsForText,
    ) {
    }

    /**
     * @param list<ProblemGroup> $groups the log groups, sorted by count
     *
     * @return array{0: list<ProblemGroup>, 1: ?string, 2: int} groups (count descending, then fingerprint), failure token, issues dropped
     */
    public function merge(Window $window, array $groups, Normalizer $normalizer): array
    {
        try {
            $records = $this->source->issues($window, $this->maxIssues);
            $built = (new IssueGroupBuilder($normalizer, $this->minGroupEventsForText))->build($records);
        } catch (SourceUnavailable $e) {
            return [$groups, in_array($e->getMessage(), DigestExtras::TOKENS, true) ? $e->getMessage() : 'unreachable', 0];
        } catch (Throwable) { // a malformed answer or a bug must not cost the log findings; the message is never read
            return [$groups, 'bad_response', 0];
        }
        $merged = [...$groups, ...$built->groups];
        usort($merged, static fn (ProblemGroup $a, ProblemGroup $b): int => [$b->count, $a->fingerprint] <=> [$a->count, $b->fingerprint]);

        return [$merged, null, $this->source->dropped() + $built->dropped];
    }
}
