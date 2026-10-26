<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\Fingerprinter;
use Watcher\RunOutcome;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/** P-H1: a snapshot without the current fpv must not compare as if history were continuous. */
final class FingerprintVersionResetTest extends TestCase
{
    public function testStaleFpVersionTriggersBaselineResetNotice(): void
    {
        $h = new Harness();
        $events = Harness::events(Level::Error, 'Alpha service exploded badly', 5, 'AlphaException');
        $source = static fn (int $n) => new FakeLogSource(Harness::totalsOf($events), Harness::events(Level::Error, 'Alpha service exploded badly', $n, 'AlphaException'));

        self::assertSame(RunOutcome::Baselined, $h->watcher($source(5))->run()->outcome);
        $h->nextWindow();

        $prevKey = Keys::snapshot('svc', Watcher::windowKey($h->windowEnd()->modify('-24 hours')));
        $stale = $h->state->get($prevKey);
        self::assertIsArray($stale);
        unset($stale['fpv']);
        $h->state->put($prevKey, $stale, 86400 * 60);

        $result = $h->watcher($source(5))->run();

        self::assertSame(RunOutcome::Baselined, $result->outcome);
        $texts = $h->sentTexts();
        self::assertNotEmpty($texts);
        $last = $texts[count($texts) - 1];
        self::assertStringContainsString('baseline reset: fingerprint rules changed (fpv 0 → ' . Fingerprinter::FPV . ')', $last);
        self::assertStringNotContainsString('baseline recorded:', $last);
    }
}
