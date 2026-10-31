<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Section\NotifySection;
use Watcher\Notify\DayGate;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\Tests\Fakes\RecordingNotifier;

final class DayGateTest extends TestCase
{
    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri'];

    public function testNothingIsSentOnTheWeekend(): void
    {
        $inner = new RecordingNotifier();
        $gate = new DayGate($inner, self::WEEKDAYS, new DateTimeZone('UTC'), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-31T10:00:00Z'));

        $gate->send(new Message('digest', []), Destination::none());

        self::assertSame([], $inner->sent, 'Saturday');
    }

    public function testMessagesAreSentOnAWeekday(): void
    {
        $inner = new RecordingNotifier();
        $gate = new DayGate($inner, self::WEEKDAYS, new DateTimeZone('UTC'), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-30T10:00:00Z'));

        $gate->send(new Message('digest', []), Destination::none());

        self::assertSame(['digest'], $inner->sent, 'Friday');
    }

    public function testTheDayIsJudgedInTheConfiguredZone(): void
    {
        $inner = new RecordingNotifier();
        // Sunday 23:30 UTC is Monday 00:30 in Berlin (CET, UTC+1 after 25 Oct 2026).
        $gate = new DayGate($inner, self::WEEKDAYS, new DateTimeZone('Europe/Berlin'), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-01T23:30:00Z'));

        $gate->send(new Message('digest', []), Destination::none());

        self::assertSame(['digest'], $inner->sent, 'Monday in Berlin');
    }

    public function testNullDaysReturnsTheNotifierUnchanged(): void
    {
        $inner = new RecordingNotifier();

        self::assertSame($inner, DayGate::wrap($inner, null, new DateTimeZone('UTC')));
    }

    public function testNotifyDaysMustBeAListOfDayNamesOrNull(): void
    {
        $this->expectException(\Watcher\Config\InvalidConfig::class);
        $this->expectExceptionMessage('notify.days must be a list of mon..sun or null');

        NotifySection::notify(['days' => ['mon', 'funday']]);
    }

    public function testWrapGatesWhenDaysAreSet(): void
    {
        $inner = new RecordingNotifier();
        $wrapped = DayGate::wrap($inner, self::WEEKDAYS, new DateTimeZone('UTC'));

        self::assertInstanceOf(Notifier::class, $wrapped);
        self::assertNotSame($inner, $wrapped);
    }
}
