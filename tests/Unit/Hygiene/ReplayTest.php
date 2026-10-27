<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\HygieneHistory;
use Watcher\Hygiene\HygieneSettings;
use Watcher\Hygiene\Proposals;
use Watcher\Hygiene\Replay;
use Watcher\Level;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class ReplayTest extends TestCase
{
    private const NOW = '2026-10-08T06:00:00+00:00';

    public function testReplayRemovesTheFamilyFromTheCandidatesOfTheDaysItWasAnalysed(): void
    {
        $days = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500, 'tokens' => 40], ['fp' => 'bbbbbbbbbbbb', 'n' => 500, 'tokens' => 70]]);
        // A family seen on a day is a candidate that day; muting removes it on every day it was seen.

        $r = Replay::mute('aaaaaaaaaaaa', $days, new DateTimeImmutable(self::NOW));

        self::assertSame(['days' => 16, 'occurrences_removed' => 16], $r);
    }

    public function testAFragmentThatMatchesNothingShowsZero(): void
    {
        $days = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500, 'tokens' => 40]]);

        self::assertSame(['days' => 16, 'occurrences_removed' => 0], Replay::mute('zzzzzzzzzzzz', $days, new DateTimeImmutable(self::NOW)));
    }

    public function testTheProposalCarriesItsReplay(): void
    {
        $history = new HygieneHistory(new InMemoryStateStore(new FrozenClock(new DateTimeImmutable(self::NOW))), 'svc', 2592000, new DateTimeZone('UTC'));
        $days = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500, 'tokens' => 40]]);
        foreach (array_slice(array_keys($days), 0, 6) as $d) {
            $days[$d] = DayFactory::day($d, [['fp' => 'aaaaaaaaaaaa', 'n' => 500, 'tokens' => 100]]);
        }

        /** @var array{replay: array{days: int, occurrences_removed: int}} $p */
        $p = (new Proposals(new HygieneSettings(enabled: true), Level::Error, [], [], $history))->find($days, new DateTimeImmutable(self::NOW))['proposals'][0];

        self::assertSame(16, $p['replay']['days']);
        self::assertSame(16, $p['replay']['occurrences_removed']);
    }

    public function testReplayDoesNotTouchTheHistory(): void
    {
        $days = DayFactory::days(16, [['fp' => 'aaaaaaaaaaaa', 'n' => 500, 'tokens' => 40]]);
        $before = serialize($days);

        Replay::mute('aaaaaaaaaaaa', $days, new DateTimeImmutable(self::NOW));

        self::assertSame($before, serialize($days));
    }
}
