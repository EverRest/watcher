<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use DateTimeImmutable;
use Watcher\Level;
use Watcher\Reduce\ProblemGroup;
use Watcher\Select\MuteList;

/**
 * What a mute would have done to the stored history (FR-012): each day's families are turned back into groups and passed
 * through a real {@see MuteList} holding the fragment. A family seen that day is a candidate; muting removes it. A fragment that matches nothing shows zero before it is pasted.
 */
final class Replay
{
    /**
     * @param array<string, DayRecord> $days
     * @return array{days: int, candidates_removed: int}
     */
    public static function mute(string $fingerprint, array $days, DateTimeImmutable $now): array
    {
        $list = new MuteList([['fingerprint' => $fingerprint, 'until' => null]]);
        $removed = 0;
        foreach ($days as $day) {
            $groups = [];
            foreach ($day->families as $f) {
                $groups[] = new ProblemGroup($f['fp'], $f['tpl'], Level::parse($f['level']), null, [], $f['n'], new DateTimeImmutable($f['first']), new DateTimeImmutable($f['last']));
            }
            $kept = array_map(static fn (ProblemGroup $g): string => $g->fingerprint, $list->filter($groups, [], $now)->kept);
            foreach ($groups as $g) {
                if (!in_array($g->fingerprint, $kept, true)) {
                    ++$removed;
                }
            }
        }

        return ['days' => count($days), 'candidates_removed' => $removed];
    }
}
