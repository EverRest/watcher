<?php

declare(strict_types=1);

namespace Watcher\Issues;

use DateTimeZone;
use Throwable;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;

/**
 * IssueRecord -> ordinary ProblemGroup (fingerprint `sentry:{id}`, never recomputed from the title) so mute, baseline,
 * selection, input cut and redaction apply unchanged. Text goes through the Normalizer here and the Redactor later (two
 * layers). A title and culprit are withheld when the issue is rare (same rule as log groups); an issue whose text cannot be
 * normalised is dropped and counted (Principle I).
 */
final class IssueGroupBuilder
{
    public const TITLE = 300;
    public const CULPRIT = 120;

    public function __construct(private readonly Normalizer $normalizer, private readonly int $minGroupEventsForText)
    {
    }

    /** @param list<IssueRecord> $records */
    public function build(array $records): IssueGroups
    {
        $groups = [];
        $regressions = [];
        $dropped = 0;
        $utc = new DateTimeZone('UTC');
        foreach ($records as $r) {
            try {
                $template = $r->countInWindow < $this->minGroupEventsForText ? null : mb_substr($this->normalizer->normalize($r->title), 0, self::TITLE); // mask, then cut
                $frames = ($r->culprit === '' || $r->countInWindow < $this->minGroupEventsForText) ? [] : [mb_substr($this->normalizer->normalize($r->culprit), 0, self::CULPRIT)];
            } catch (Throwable) {
                ++$dropped;
                continue;
            }
            $fp = 'sentry:' . $r->id;
            $groups[] = new ProblemGroup($fp, $template, $r->level, null, $frames, $r->countInWindow, $r->firstSeen->setTimezone($utc), $r->lastSeen->setTimezone($utc), null, GroupStatus::New);
            if ($r->isRegression) {
                $regressions[] = $fp;
            }
        }

        return new IssueGroups($groups, $regressions, $dropped);
    }
}
