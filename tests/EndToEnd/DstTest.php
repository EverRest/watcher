<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeLogSource;

/** A daylight-saving change must not cost a baseline: consecutive windows share boundaries (audit P1). */
final class DstTest extends TestCase
{
    /** @return iterable<string, array{list<string>}> */
    public static function changes(): iterable
    {
        yield 'Kyiv fall back 2026-10-25' => [['2026-10-24', '2026-10-25', '2026-10-26']];
        yield 'Kyiv spring forward 2026-03-29' => [['2026-03-28', '2026-03-29', '2026-03-30']];
    }

    /** @param list<string> $days */
    #[DataProvider('changes')]
    public function testNoNewBaselineAcrossTheChange(array $days): void
    {
        $h = new Harness(['schedule' => ['run_at' => '06:00', 'timezone' => 'Europe/Kyiv']]);
        $events = Harness::events(Level::Error, 'Alpha service exploded badly', 5, 'AlphaException');
        $source = new FakeLogSource(Harness::totalsOf($events), $events);

        $outcomes = [];
        foreach ($days as $day) {
            $h->clock->set((new DateTimeImmutable("{$day} 06:10", new DateTimeZone('Europe/Kyiv'))));
            $outcomes[] = $h->watcher($source)->run()->outcome;
        }

        self::assertSame(RunOutcome::Baselined, $outcomes[0]);
        self::assertContains($outcomes[1], [RunOutcome::Reported, RunOutcome::Quiet]);
        self::assertContains($outcomes[2], [RunOutcome::Reported, RunOutcome::Quiet]);
    }
}
