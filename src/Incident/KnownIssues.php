<?php

declare(strict_types=1);

namespace Watcher\Incident;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Reduce\ProblemGroup;

final class KnownIssues
{
    public const MAX_LINES = 10;

    /** @param list<KnownIssue> $entries */
    public function __construct(
        private readonly array $entries,
        private readonly float $growthFactor,
    ) {
    }

    /** @param list<ProblemGroup> $groups */
    public function partition(array $groups, DateTimeImmutable $now, KnownReferences $refs): KnownResult
    {
        if ($this->entries === []) {
            return new KnownResult($groups, [], [], [], 0, 0);
        }

        $today = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $kept = [];
        $lines = [];
        $grown = [];
        $labels = [];
        $knownCount = 0;
        $knownGrownCount = 0;

        foreach ($groups as $g) {
            $matched = $this->matching($g->fingerprint);
            if ($matched === []) {
                $kept[] = $g;
                continue;
            }
            $ticketLabel = implode('/', array_map(static fn (KnownIssue $e): string => $e->ticket, $matched));
            $anyResolved = false;
            $anyExpired = false;
            foreach ($matched as $e) {
                $anyResolved = $anyResolved || $e->resolved;
                $anyExpired = $anyExpired || !$e->activeOn($today);
            }
            if ($anyResolved) {
                $kept[] = $g;
                $labels[$g->fingerprint] = 'regression of ' . $ticketLabel . ' (marked resolved)';
                continue;
            }
            if ($anyExpired) {
                $kept[] = $g;
                $labels[$g->fingerprint] = 'link ' . $ticketLabel . ' expired';
                continue;
            }
            $ref = $refs->touch($g->fingerprint, $g->count, $now);
            if ($g->count >= $this->growthFactor * $ref) {
                $kept[] = $g;
                $grown[$g->fingerprint] = true;
                $labels[$g->fingerprint] = 'known, grown · ' . $ticketLabel;
                ++$knownGrownCount;
                continue;
            }
            $lines[] = new KnownLine($ticketLabel, $g->level, $g->count, $g->fingerprint);
            ++$knownCount;
        }

        usort($lines, static fn (KnownLine $a, KnownLine $b): int => [$b->count, $b->fingerprint] <=> [$a->count, $a->fingerprint]);

        return new KnownResult($kept, $lines, $grown, $labels, $knownCount, $knownGrownCount);
    }

    /** @return list<KnownIssue> */
    private function matching(string $fingerprint): array
    {
        $out = [];
        foreach ($this->entries as $e) {
            if ($e->matches($fingerprint)) {
                $out[] = $e;
            }
        }

        return $out;
    }
}
