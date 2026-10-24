<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\RoutingNotifier;
use Watcher\Tests\Fakes\FakeNotifier;

final class RoutingNotifierTest extends TestCase
{
    private const TOKEN = '123456:ABCdefGHIjklMNOpqrSTUvwxYZ0123456789';

    public function testEachDriverReachesItsOwnNotifier(): void
    {
        [$slack, $telegram, $discord] = [new FakeNotifier(), new FakeNotifier(), new FakeNotifier()];
        $router = new RoutingNotifier($slack, $telegram, $discord);
        $m = new Message('x');

        $router->send($m, Destination::slack('https://hooks.invalid/x'));
        $router->send($m, Destination::telegram(self::TOKEN, '-100123'));
        $router->send($m, Destination::discord('https://discord.com/api/webhooks/1/abc'));
        $router->send($m, new Destination('https://hooks.invalid/y'));

        self::assertCount(2, $slack->sent);
        self::assertCount(1, $telegram->sent);
        self::assertCount(1, $discord->sent);
    }

    public function testAnInnerFailurePropagatesUntouched(): void
    {
        $telegram = new FakeNotifier();
        $e = new DeliveryFailed('telegram delivery failed: HTTP 403');
        $telegram->failWith($e);
        $router = new RoutingNotifier(new FakeNotifier(), $telegram, new FakeNotifier());

        try {
            $router->send(new Message('x'), Destination::telegram(self::TOKEN, '-100123'));
            self::fail('expected DeliveryFailed');
        } catch (DeliveryFailed $caught) {
            self::assertSame($e, $caught);
        }
    }
}
