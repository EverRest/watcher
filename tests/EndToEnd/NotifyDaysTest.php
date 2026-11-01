<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Source\SourceUnavailable;
use Watcher\Tests\Fakes\FakeLogSource;

/** notify.days through a real run. The harness clock is Thursday 2026-10-08, so ['mon'] is a day off. */
final class NotifyDaysTest extends TestCase
{
    private const ALERTS = ['alerts' => ['enabled' => true, 'immediate' => ['health-check' => ['level' => 'critical', 'message' => 'health check failed']]]];

    private static function source(): FakeLogSource
    {
        $events = Harness::events(Level::Critical, 'health check failed', 2);

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    public function testDigestAndAlertsAreDroppedOnADayOff(): void
    {
        $h = new Harness([...self::ALERTS, 'notify' => ['days' => ['mon']]]);

        $result = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertSame([], $h->notifier->sent);
    }

    public function testDigestAndAlertsAreSentOnAnAllowedDay(): void
    {
        $h = new Harness([...self::ALERTS, 'notify' => ['days' => ['thu']]]);

        $h->watcher(self::source())->run();

        self::assertCount(2, $h->notifier->sent, 'the alert and the digest');
    }

    /** A failure on a day off must still reach the notice destination: only the digest and alerts are gated. */
    public function testAFailureNoticeIsSentOnADayOff(): void
    {
        $h = new Harness(['notify' => ['days' => ['mon']]]);
        $src = self::source();
        $src->failWith(new SourceUnavailable('loki 503'));

        self::assertSame(RunOutcome::Failed, $h->watcher($src)->run()->outcome);

        self::assertCount(1, $h->sentTexts());
        self::assertStringContainsString('source failed', $h->sentTexts()[0]);
    }
}
