<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeLogSource;

final class CatchUpTest extends TestCase
{
    /** @param array<string, mixed> $patch */
    private static function hourly(array $patch = []): Harness
    {
        return new Harness(array_replace_recursive([
            'schedule' => [
                'every' => 'PT1H',
                'timezone' => 'UTC',
                'run_at' => null,
                'catch_up' => 1,
            ],
        ], $patch));
    }

    public function testOutageRunsOneWindowAndSaysSo(): void
    {
        $h = self::hourly();
        $h->clock->set(new DateTimeImmutable('2026-10-08T09:30:00+00:00'));
        $events = Harness::events(Level::Error, 'outage seed', 3);
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->clock->advance('+5 hours');
        $h->watcher(new FakeLogSource([], []))->run();
        self::assertStringContainsString('not analysed (4 window(s) missing or failed)', implode("\n", $h->sentTexts()));
    }

    public function testCatchUpThree(): void
    {
        $h = self::hourly(['schedule' => ['catch_up' => 3, 'lock_ttl' => 4000, 'run_deadline' => 3900]]);
        $h->clock->set(new DateTimeImmutable('2026-10-08T09:30:00+00:00'));
        $events = Harness::events(Level::Error, 'catch-up seed', 2);
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        self::assertInstanceOf(\Watcher\Tests\Fakes\InMemoryStateStore::class, $h->state);
        $runsBefore = count(array_filter(array_keys($h->state->all()), static fn (string $k): bool => str_starts_with($k, 'run:')));
        $h->clock->advance('+5 hours');
        $h->watcher(new FakeLogSource([], []))->run();
        $runsAfter = count(array_filter(array_keys($h->state->all()), static fn (string $k): bool => str_starts_with($k, 'run:')));
        self::assertSame(3, $runsAfter - $runsBefore);
    }

    public function testQuietRunStillReportsGap(): void
    {
        $h = self::hourly();
        $h->clock->set(new DateTimeImmutable('2026-10-08T09:30:00+00:00'));
        $events = Harness::events(Level::Error, 'quiet gap seed', 2);
        $h->watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->clock->advance('+5 hours');
        $r = $h->watcher(new FakeLogSource([], []))->run();
        self::assertSame(RunOutcome::Quiet, $r->outcome);
        self::assertStringContainsString('not analysed', implode("\n", $h->sentTexts()));
    }
}
