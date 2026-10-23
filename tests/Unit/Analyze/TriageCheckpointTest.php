<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\TriageCheckpoint;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class TriageCheckpointTest extends TestCase
{
    private InMemoryStateStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-23T06:00:00+00:00')));
    }

    private function checkpoint(string $window = '2026-10-23T06:00:00Z'): TriageCheckpoint
    {
        return new TriageCheckpoint($this->store, 'svc', $window);
    }

    /** @param list<string> $watch */
    private static function group(string $fp, Level $level = Level::Warning, array $watch = []): RedactedGroup
    {
        return new RedactedGroup($fp, 'tpl ' . $fp, $level, null, [], 5, null, GroupStatusLabel::New, $watch, new DateTimeImmutable(), new DateTimeImmutable());
    }

    public function testRoundTripKeepsTriageVerdictsForSixHours(): void
    {
        $groups = [self::group('a'), self::group('b'), self::group('c', Level::Error)];

        $this->checkpoint()->save(['a' => 'noise', 'b' => 'known', 'c' => 'interesting'], $groups);

        self::assertSame(['a' => 'noise', 'b' => 'known', 'c' => 'interesting'], $this->checkpoint()->load($groups));
        self::assertSame(6 * 3600, $this->store->ttls()[Keys::triage('svc', '2026-10-23T06:00:00Z')]);
    }

    public function testNoiseAndKnownAreNeverKeptForErrorOrWatchRuleGroups(): void
    {
        $groups = [self::group('err', Level::Error), self::group('watch', Level::Warning, ['rule']), self::group('warn')];

        $this->checkpoint()->save(['err' => 'noise', 'watch' => 'known', 'warn' => 'noise'], $groups);

        self::assertSame(['warn' => 'noise'], $this->checkpoint()->load($groups));
    }

    public function testLoadDropsVerdictsTheStoreShouldNotHaveHeld(): void
    {
        // Another writer (or an older version) stored a noise verdict for an error group: it is not trusted on the way out either.
        $this->store->put(Keys::triage('svc', 'w'), ['err' => 'noise', 'warn' => 'bogus', 'gone' => 'noise', 'ok' => 'noise'], 60);
        $groups = [self::group('err', Level::Error), self::group('warn'), self::group('ok')];

        self::assertSame(['ok' => 'noise'], $this->checkpoint('w')->load($groups));
    }

    public function testOtherWindowsAndMissingRecordsGiveNothing(): void
    {
        $groups = [self::group('a')];
        $this->checkpoint('w1')->save(['a' => 'noise'], $groups);

        self::assertSame([], $this->checkpoint('w2')->load($groups));
    }

    public function testClearForgetsTheRecord(): void
    {
        $groups = [self::group('a')];
        $this->checkpoint()->save(['a' => 'noise'], $groups);

        $this->checkpoint()->clear();

        self::assertSame([], $this->checkpoint()->load($groups));
    }

    public function testNothingWorthKeepingWritesNothing(): void
    {
        $this->checkpoint()->save(['err' => 'noise'], [self::group('err', Level::Error)]);

        self::assertSame([], $this->store->all());
    }
}
