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
     * @param list<ProblemGroup>          $groups
     * @param array<string, list<string>> $eventMatches fingerprint => ENTRY INDEXES (as strings, position in the
     *                                                  mute list) of field entries that matched any event of the group
     */
    public function filter(array $groups, array $eventMatches, DateTimeImmutable $now): MuteResult
    {
        $today = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $kept = [];
        $muted = 0;
        foreach ($groups as $g) {
            if ($this->isMuted($g, $eventMatches[$g->fingerprint] ?? [], $today)) {
                $muted++;
            } else {
                $kept[] = $g;
            }
        }

        return new MuteResult($kept, $muted);
    }

    /** @param list<string> $fieldHits */
    private function isMuted(ProblemGroup $g, array $fieldHits, string $today): bool
    {
        foreach ($this->entries as $i => $e) {
            if ($e['until'] !== null && $e['until'] < $today) { // inclusive through that UTC day
                continue;
            }
            $m = $e['matcher'];
            if ($e['fingerprint'] === $g->fingerprint
                || ($m !== null && ($m->isFieldMatcher() ? in_array((string) $i, $fieldHits, true) : $m->matchesGroup($g)))) {
                return true;
            }
        }

        return false;
    }
}
