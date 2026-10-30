<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Probe;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Probe\CertificateProbe;
use Watcher\Probe\Probe;
use Watcher\Probe\ProbeRunner;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Alerts on status change, re-alerts when the dedupe key expires, never alerts on unknown, and runs once per interval. */
final class ProbeRunnerTest extends TestCase
{
    private const HOUR = 3600;

    private FrozenClock $clock;
    private InMemoryStateStore $state;
    private FakeNotifier $notifier;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-28 12:00:00 UTC'));
        $this->state = new InMemoryStateStore($this->clock);
        $this->notifier = new FakeNotifier();
    }

    /** @param list<Probe> $probes */
    private function runner(array $probes): ProbeRunner
    {
        return new ProbeRunner($probes, $this->state, $this->notifier, Destination::none(), 'orders-api', self::HOUR);
    }

    /** A probe whose certificate ends $days from now, or is unreachable when $days is null. */
    private function certificate(?int &$days): CertificateProbe
    {
        $peer = function () use (&$days): ?array {
            return $days === null ? null : ['validTo_time_t' => $this->clock->now()->getTimestamp() + $days * 86400];
        };

        return new CertificateProbe(['api.example.test'], 30, 7, $this->clock, $peer);
    }

    public function testAlertsOnceThenReAlertsWhenTheDedupeKeyExpires(): void
    {
        $days = 20;
        $runner = $this->runner([$this->certificate($days)]);

        $runner->runDue(); // T: first sighting of a warning
        self::assertCount(1, $this->notifier->sent);
        self::assertStringContainsString('WARN', $this->notifier->last()['message']->text);

        for ($hour = 1; $hour < 24; ++$hour) { // T+1h .. T+23h: the key lives for 24 h, so nothing repeats
            $this->clock->advance('+1 hour');
            $runner->runDue();
        }
        self::assertCount(1, $this->notifier->sent);

        $this->clock->advance('+1 hour'); // T+24h: the dedupe key has expired, the warning still stands
        $runner->runDue();
        self::assertCount(2, $this->notifier->sent);
    }

    public function testRecoveryIsAlertedOnce(): void
    {
        $days = 20;
        $runner = $this->runner([$this->certificate($days)]);
        $runner->runDue();

        $days = 60;
        $this->clock->advance('+1 hour');
        $runner->runDue();
        self::assertCount(2, $this->notifier->sent);
        self::assertStringContainsString('ok', $this->notifier->last()['message']->text);

        $this->clock->advance('+1 hour');
        $runner->runDue();
        self::assertCount(2, $this->notifier->sent);
    }

    public function testAnUnreadableHostNeverAlerts(): void
    {
        $days = null;
        $this->runner([$this->certificate($days)])->runDue();

        self::assertSame([], $this->notifier->sent);
        self::assertSame([], array_filter(array_keys($this->state->all()), static fn (string $k): bool => str_starts_with($k, 'probe:')));
    }

    public function testChecksRunOncePerIntervalEvenWhenCalledTwice(): void
    {
        $probe = new class () implements Probe {
            public int $checks = 0;

            public function name(): string
            {
                return 'counting';
            }

            public function check(): array
            {
                ++$this->checks;

                return [];
            }
        };
        $runner = $this->runner([$probe]);

        $runner->runDue();
        $runner->runDue();
        self::assertSame(1, $probe->checks);

        $this->clock->advance('+1 hour');
        $runner->runDue();
        self::assertSame(2, $probe->checks);
    }

    public function testAFailedDeliveryIsTriedAgainOnTheNextRun(): void
    {
        $days = 1;
        $runner = $this->runner([$this->certificate($days)]);
        $this->notifier->failOnce(new DeliveryFailed('chat down'));

        $runner->runDue();
        self::assertSame([], $this->notifier->sent);

        $this->clock->advance('+1 hour');
        $runner->runDue();
        self::assertCount(1, $this->notifier->sent);
    }

    public function testAProbeErrorNeverEscapesTheRun(): void
    {
        $failing = new class () implements Probe {
            public function name(): string
            {
                return 'broken';
            }

            public function check(): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $this->runner([$failing])->runDue(); // must return normally

        self::assertSame([], $this->notifier->sent);
    }
}
