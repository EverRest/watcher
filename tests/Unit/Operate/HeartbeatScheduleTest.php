<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Notify\Destination;
use Watcher\Notify\NoticeRenderer;
use Watcher\Operate\Heartbeat;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Spec 014 US8: the weekly line of a multi-run schedule reports runs per day, not skips (a 15-minute trigger skips often). */
final class HeartbeatScheduleTest extends TestCase
{
    private const RETENTION = 2592000;

    public function testMultiRunWeeklyLineCountsRunsAndHasNoSkipCounter(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-21T06:00:40+00:00')); // W43
        $store = new InMemoryStateStore($clock);
        $hb = new Heartbeat($store, $clock, 'svc', new DateTimeZone('UTC'), self::RETENTION);
        $hb->recordRun(RunOutcome::Reported, new DateTimeImmutable('2026-10-21T05:00Z'), new DateTimeImmutable('2026-10-21T06:00Z'), new DateTimeImmutable('2026-10-21T06:00:01Z'), 0.0, null);
        // Triggers every 15 minutes after the hour's run was already done: these are skips, not runs.
        for ($i = 0; $i < 3; ++$i) {
            $hb->recordSkip(RunOutcome::SkippedDone);
        }
        $clock->set(new DateTimeImmutable('2026-10-26T06:00:10+00:00'));
        $notifier = new FakeNotifier();

        self::assertTrue($hb->sendWeeklySummary($notifier, new Destination('https://hooks.example/x'), new NoticeRenderer(), 'stg', 24));

        $text = $notifier->last()['message']->text;
        self::assertStringContainsString('runs/day 24', $text);
        self::assertStringNotContainsString('skipped', $text);
    }
}
