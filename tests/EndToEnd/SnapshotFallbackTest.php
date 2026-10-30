<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** H6: a skipped or failed day must not silently become "baseline recorded". */
final class SnapshotFallbackTest extends TestCase
{
    private static function source(int $n = 5, Level $level = Level::Error): FakeLogSource
    {
        $events = Harness::events($level, 'Alpha service exploded badly', $n, 'AlphaException');

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    public function testSkippedDayIsComparedWithTheLastGoodRunAndSaysSo(): void
    {
        $h = new Harness();
        $h->watcher(self::source())->run(); // 2026-10-08: the snapshot is what the skipped day falls back to
        $h->clock->advance('+48 hours'); // 2026-10-09 never ran

        $result = $h->watcher(self::source(20))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $text = implode("\n", $h->sentTexts());
        self::assertStringContainsString('compared with the run of 2026-10-08, 2 days ago', $text);
        self::assertStringContainsString('no snapshot for the previous window', $text);
        self::assertStringContainsString('not scaled', $text);
        self::assertStringContainsString('↑ from 5', $text, 'growth is measured against the older snapshot');
    }

    public function testNoSnapshotAtAllIsStillABaselineWhenNothingIsAtErrorLevel(): void
    {
        $h = new Harness();

        $result = $h->watcher(self::source(5, Level::Warning))->run();

        self::assertSame(RunOutcome::Baselined, $result->outcome);
        self::assertStringContainsString('baseline', implode("\n", $h->sentTexts()));
    }

    public function testFirstRunWithErrorGroupsReportsThemInsteadOfBaselining(): void
    {
        $h = new Harness();

        $result = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $text = implode("\n", $h->sentTexts());
        self::assertStringContainsString('5× (new)', $text);
        self::assertStringNotContainsString('baseline recorded', $text);
        self::assertNotNull($h->state->get(Keys::snapshot('svc', \Watcher\Watcher::windowKey($h->windowEnd()))), 'the baseline is still recorded for tomorrow');
    }

    public function testAPointerOlderThanSevenDaysIsABaseline(): void
    {
        $h = new Harness();
        $h->watcher(self::source(5, Level::Warning))->run();
        $h->clock->advance('+8 days');

        self::assertSame(RunOutcome::Baselined, $h->watcher(self::source(5, Level::Warning))->run()->outcome);
    }

    public function testExactlySevenDaysStillCompares(): void
    {
        $h = new Harness();
        $h->watcher(self::source())->run();
        $h->clock->advance('+7 days');

        self::assertSame(RunOutcome::Reported, $h->watcher(self::source(20))->run()->outcome);
        self::assertStringContainsString('7 days ago', implode("\n", $h->sentTexts()));
    }

    public function testAnOrdinaryNextDayNeverMentionsAFallback(): void
    {
        $h = new Harness();
        $h->watcher(self::source())->run();
        $h->nextWindow();

        $h->watcher(self::source(20))->run();

        self::assertStringNotContainsString('compared with the run of', implode("\n", $h->sentTexts()));
    }

    public function testThePointerIsWrittenWithTheRetentionTtl(): void
    {
        $h = new Harness();

        $h->watcher(self::source())->run();

        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $ttl = $h->state->ttls()[Keys::lastSnapshot('svc')] ?? null;
        self::assertSame(\Watcher\Config\WatchConfig::fromArray($h->config())->state['retention'], $ttl);
        self::assertSame(['end' => \Watcher\Watcher::windowKey($h->windowEnd())], $h->state->get(Keys::lastSnapshot('svc')));
    }
}
