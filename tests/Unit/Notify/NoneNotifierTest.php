<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\NoneNotifier;
use Watcher\Notify\RoutingNotifier;
use Watcher\Tests\Fakes\FakeNotifier;

final class NoneNotifierTest extends TestCase
{
    public function testNoneDestinationCarriesNothing(): void
    {
        $d = Destination::none();

        self::assertSame('none', $d->driver());
        self::assertNull($d->invalidKey());
        foreach (['webhook', 'botToken', 'chatId', 'threadId'] as $accessor) {
            try {
                $d->{$accessor}();
                self::fail($accessor . ' must refuse a none destination');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\LogicException::class);
        serialize($d);
    }

    public function testSameAs(): void
    {
        self::assertTrue(Destination::none()->sameAs(Destination::none()));
        self::assertFalse(Destination::none()->sameAs(Destination::slack('https://hooks.invalid/x')));
    }

    public function testTheRouterDropsNoneWithoutTouchingAnotherNotifier(): void
    {
        [$slack, $telegram, $discord] = [new FakeNotifier(), new FakeNotifier(), new FakeNotifier()];
        (new RoutingNotifier($slack, $telegram, $discord))->send(new Message('x'), Destination::none());

        self::assertSame(0, $slack->attempts + $telegram->attempts + $discord->attempts);
    }

    public function testTheNoneNotifierAcceptsAndDrops(): void
    {
        (new NoneNotifier())->send(new Message('x'), Destination::none());
        $this->addToAssertionCount(1);
    }
}
