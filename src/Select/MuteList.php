<?php

declare(strict_types=1);

namespace Watcher\Select;

use DateTimeImmutable;
use DateTimeZone;
use Watcher\Reduce\ProblemGroup;

/** Suppresses known-noisy groups before candidate selection, so they never reach a model. */
final class MuteList
{
    /** @var list<array{fingerprint: ?string, matcher: ?Matcher, until: ?string}> */
    private array $entries = [];

    /** @param list<array<string, mixed>> $mute WatchConfig::$mute */
    public function __construct(array $mute)
    {
        foreach ($mute as $e) {
            $fp = isset($e['fingerprint']) ? (string) $e['fingerprint'] : null;
            $this->entries[] = [
                'fingerprint' => $fp,
                'matcher' => $fp === null ? MatcherParser::parse($e) : null,
                'until' => isset($e['until']) ? (string) $e['until'] : null,
            ];
        }
    }

    /** Regex evaluations that errored: such an entry does not mute, so the group stays visible. */
    public function failures(): int
    {
        return array_sum(array_map(static fn (array $e): int => $e['matcher']->failures ?? 0, $this->entries));
    }

    /**
     * @param list<ProblemGroup>           $groups
     * @param array<string, array<int, int>> $eventMatches fingerprint => [mute entry index => number of the group's events
     *                                                     that matched that field entry]. A field entry mutes a group only
     *                                                     when it matched every event of it.
     */
    public function filter(array $groups, array $eventMatches, DateTimeImmutable $now): MuteResult
    {
        $today = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $kept = [];
        $partial = [];
        $muted = 0;
        foreach ($groups as $g) {
            $counts = $eventMatches[$g->fingerprint] ?? [];
            if ($this->isMuted($g, $counts, $today)) {
                $muted++;
                continue;
            }
            $kept[] = $g;
            $best = null;
            foreach ($counts as $i => $n) {
                if ($n > 0 && $this->active($i, $today) && ($best === null || $n > $best[0])) {
                    $best = [$n, $g->count, $i];
                }
            }
            if ($best !== null) {
                $partial[$g->fingerprint] = $best;
            }
        }

        return new MuteResult($kept, $muted, $partial);
    }

    private function active(int $i, string $today): bool
    {
        $until = $this->entries[$i]['until'] ?? null;

        return $until === null || $until >= $today; // inclusive through that UTC day
    }

    /** @param array<int, int> $fieldCounts */
    private function isMuted(ProblemGroup $g, array $fieldCounts, string $today): bool
    {
        foreach ($this->entries as $i => $e) {
            if (!$this->active($i, $today)) {
                continue;
            }
            $m = $e['matcher'];
            if ($e['fingerprint'] === $g->fingerprint
                || ($m !== null && ($m->isFieldMatcher() ? ($fieldCounts[$i] ?? 0) >= $g->count && $g->count > 0 : $m->matchesGroup($g)))) {
                return true;
            }
        }

        return false;
    }
}
