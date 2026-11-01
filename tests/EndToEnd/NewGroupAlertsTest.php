<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\FakeLogSource;

/** Spec 018 US2: a new error group is sent out of turn, in the run that sees it, before that run's digest. */
final class NewGroupAlertsTest extends TestCase
{
    /** @param list<LogEvent> $events */
    private static function source(array $events): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    public function testANewErrorGroupIsSentBeforeTheDigestOfTheSameRun(): void
    {
        $h = new Harness(['alerts' => ['new_groups' => true]]);
        $alpha = Harness::events(Level::Error, 'Alpha service exploded badly', 5, 'AlphaException');
        $h->watcher(self::source($alpha))->run();
        $h->nextWindow();

        $beta = Harness::events(Level::Error, 'Beta service exploded badly', 3, 'BetaException');
        $h->watcher(self::source([...$alpha, ...$beta]))->run();

        $texts = $h->sentTexts();
        $alert = array_search(true, array_map(static fn (string $t): bool => str_contains($t, 'new error group in'), $texts), true);
        $digest = array_search(true, array_map(static fn (string $t): bool => str_contains($t, '🔎'), $texts), true);
        self::assertNotFalse($alert, 'the out-of-turn alert was sent');
        self::assertNotFalse($digest, 'the digest was sent');
        self::assertLessThan($digest, $alert, 'the alert comes before the digest of the same run');
    }
}
