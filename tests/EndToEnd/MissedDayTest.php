<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Source\SourceUnavailable;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/** A missed or failed day must neither reset the comparison nor leave a half-written record (spec 004 US1). */
final class MissedDayTest extends TestCase
{
    private static function source(int $n): FakeLogSource
    {
        $events = Harness::events(Level::Error, 'Alpha service exploded badly', $n, 'AlphaException');

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    private static function down(): FakeLogSource
    {
        $source = self::source(5);
        $source->failWith(new SourceUnavailable('loki 503 Service Unavailable'));

        return $source;
    }

    public function testMissedMiddleDayStillReports(): void
    {
        $h = new Harness();
        // The Error first run now reports (FR-035); the later growth check still compares with it.
        $h->watcher(self::source(5))->run();
        $h->nextWindow();
        self::assertSame(RunOutcome::Failed, $h->watcher(self::down())->run()->outcome);
        $h->nextWindow();

        $result = $h->watcher(self::source(20))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $text = implode("\n", $h->sentTexts());
        self::assertStringContainsString('compared with the run of 2026-10-08', $text);
        self::assertStringContainsString('↑ from 5', $text);
    }

    public function testTooOldRecordsBaseline(): void
    {
        $h = new Harness();
        $h->watcher(self::source(5))->run();
        $h->clock->advance('+8 days');

        // The Error group makes this baseline run report (FR-035), so it is no longer Baselined.
        self::assertSame(RunOutcome::Reported, $h->watcher(self::source(20))->run()->outcome);
        self::assertStringNotContainsString('compared with', implode("\n", $h->sentTexts()));
    }

    public function testFailedRunKeepsOlderSnapshotUsable(): void
    {
        $h = new Harness();
        $h->watcher(self::source(5))->run();
        $firstEnd = Watcher::windowKey($h->windowEnd());
        $h->nextWindow();
        $h->watcher(self::down())->run();

        self::assertNull($h->state->get(Keys::snapshot('svc', Watcher::windowKey($h->windowEnd()))), 'a failed run writes no snapshot');
        self::assertSame(['end' => $firstEnd], $h->state->get(Keys::lastSnapshot('svc')), 'and does not move the pointer');
    }

    public function testSnapshotRecordsWindowStart(): void
    {
        $h = new Harness();
        $h->watcher(self::source(5))->run();

        $snap = $h->state->get(Keys::snapshot('svc', Watcher::windowKey($h->windowEnd())));

        self::assertIsArray($snap);
        self::assertSame('2026-10-07T06:00:00Z', $snap['start'] ?? null);
    }
}
