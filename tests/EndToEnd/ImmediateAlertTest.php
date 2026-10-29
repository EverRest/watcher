<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\SpyLogger;

/** alerts.immediate through a real run: sent during the read, beside the digest, and never able to fail the run. */
final class ImmediateAlertTest extends TestCase
{
    private const ENABLED = ['alerts' => ['enabled' => true, 'immediate' => ['health-check' => ['level' => 'critical', 'message' => 'health check failed']]]];

    /** One read: two matching critical lines and an error the rule does not match. */
    private static function source(): FakeLogSource
    {
        $events = [...Harness::events(Level::Critical, 'health check failed', 2), ...Harness::events(Level::Error, 'db timeout', 1)];

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /** @return list<string> */
    private static function alerts(Harness $h): array
    {
        return array_values(array_filter($h->sentTexts(), static fn (string $t): bool => str_starts_with($t, '🚨 ALERT')));
    }

    public function testAMatchingLineIsSentOnceWithACountAndTheRunGoesOn(): void
    {
        $h = new Harness(self::ENABLED);

        $result = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::Baselined, $result->outcome);
        $alerts = self::alerts($h);
        self::assertCount(1, $alerts);
        self::assertStringStartsWith("🚨 ALERT [critical] svc: health check failed (×2)\nstg · 2026-10-07 12:00:00 UTC", $alerts[0]);
        self::assertCount(2, $h->notifier->sent, 'the alert and the baseline notice');
    }

    public function testNothingIsSentWhileTheSwitchIsOff(): void
    {
        $h = new Harness();

        $h->watcher(self::source())->run();

        self::assertSame([], self::alerts($h));
    }

    public function testAFailedAlertIsLoggedAndTheRunStillCompletes(): void
    {
        $h = new Harness(self::ENABLED);
        $h->hostLog = $log = new SpyLogger();
        $h->notifier->failOnce(new DeliveryFailed('slack delivery failed: HTTP 500'));

        $result = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::Baselined, $result->outcome);
        self::assertSame([], self::alerts($h));
        self::assertCount(1, $h->notifier->sent, 'the baseline notice still goes out');
        self::assertSame('warning', $log->records[0][0]);
        self::assertStringStartsWith('immediate alert failed: ', $log->records[0][1]);
    }
}
