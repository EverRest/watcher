<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeLogSource;

/** FR-008: a group that is still there and has not grown is sent again with the stored finding, with no model call. */
final class RepeatDigestTest extends TestCase
{
    private static function source(int $n = 5): FakeLogSource
    {
        $events = Harness::events(Level::Error, 'Alpha service exploded badly', $n, 'AlphaException');

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    public function testUnchangedErrorsAreSentAgainWithTheSameFindingAndNoModelCall(): void
    {
        $h = new Harness();
        $h->watcher(self::source())->run();
        $requestsAfterFirst = count($h->analyzer->allRequests());
        $h->nextWindow();

        $result = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertCount($requestsAfterFirst, $h->analyzer->allRequests(), 'a repeat must not call the model');
        $texts = $h->sentTexts();
        $last = end($texts);
        self::assertIsString($last);
        self::assertStringContainsString('Hypothesis: a dependency may be failing', $last, 'the stored finding is sent again');
        self::assertStringContainsString('5×', $last);
    }

    public function testNoErrorsTodayMeansNoRepeat(): void
    {
        $h = new Harness();
        $h->watcher(self::source())->run();
        $h->nextWindow();

        $result = $h->watcher(new FakeLogSource([], []))->run();

        self::assertSame(RunOutcome::Quiet, $result->outcome);
    }

    public function testAGroupThatGrewIsAnalysedAgain(): void
    {
        $h = new Harness();
        $h->watcher(self::source())->run();
        $requestsAfterFirst = count($h->analyzer->allRequests());
        $h->nextWindow();

        $result = $h->watcher(self::source(20))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertGreaterThan($requestsAfterFirst, count($h->analyzer->allRequests()), 'growth goes to the model');
    }
}
