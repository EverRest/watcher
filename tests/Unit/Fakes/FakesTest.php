<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fakes;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Schedule\Window;
use Watcher\Source\LogEvent;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;

final class FakesTest extends TestCase
{
    private static function event(Level $level, string $message = 'm'): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-01-01T00:00:00Z'), $level, $message, null, [], [], []);
    }

    private static function window(): Window
    {
        return Window::endingAt(new DateTimeImmutable('2026-01-02T00:00:00Z'));
    }

    public function testLinesHonourMinLevelWatchPatternsAndCap(): void
    {
        $src = new FakeLogSource([], [
            self::event(Level::Info),
            self::event(Level::Error, 'a'),
            self::event(Level::Info, 'slow query'),
            self::event(Level::Critical, 'b'),
        ]);
        $sel = new StreamSelector([]);

        $levels = static fn (\Traversable $it): array => array_map(
            static fn (LogEvent $e): string => $e->level->name . ':' . $e->message,
            iterator_to_array($it, false),
        );

        self::assertSame(['Error:a', 'Critical:b'], $levels($src->lines(self::window(), $sel, new ReadFilter(Level::Error, [], 10))));
        self::assertSame(['Error:a', 'Info:slow query', 'Critical:b'], $levels($src->lines(self::window(), $sel, new ReadFilter(Level::Error, ['slow'], 10))));
        self::assertSame(['Error:a'], $levels($src->lines(self::window(), $sel, new ReadFilter(Level::Error, [], 1))));
    }

    public function testRecordsCallsSoTestsCanAssertNoneHappened(): void
    {
        $src = new FakeLogSource(['error' => 4], [], ['1.0' => new DateTimeImmutable('2026-01-01T00:00:00Z')]);
        $sel = new StreamSelector(['app' => 'x']);

        self::assertSame([], $src->callsTo('lines'));
        self::assertSame(['error' => 4], $src->totals(self::window(), $sel));
        self::assertArrayHasKey('1.0', $src->versions(self::window(), $sel, 'version'));
        self::assertCount(1, $src->callsTo('totals'));
        self::assertSame('version', $src->callsTo('versions')[0][2]);
        self::assertSame([], $src->callsTo('lines'));
    }

    public function testSourceCanBeToldToFailOnEveryMethod(): void
    {
        $src = new FakeLogSource();
        $src->failWith(new SourceUnavailable('down'));
        $sel = new StreamSelector([]);

        foreach ([
            static fn () => $src->totals(self::window(), $sel),
            static fn () => $src->versions(self::window(), $sel, 'v'),
            static fn () => iterator_to_array($src->lines(self::window(), $sel, new ReadFilter(Level::Error, [], 1))),
        ] as $call) {
            try {
                $call();
                self::fail('expected SourceUnavailable');
            } catch (SourceUnavailable) {
                self::assertTrue(true);
            }
        }
    }

    public function testNotifierRecordsAndCanFail(): void
    {
        $n = new FakeNotifier();
        $to = new Destination('https://hooks.example/x');
        $m = new Message('hi');

        $n->send($m, $to);
        self::assertSame($m, $n->sent[0]['message']);
        self::assertSame($to, $n->sent[0]['to']);

        $n->failWith(new DeliveryFailed('nope'));
        $this->expectException(DeliveryFailed::class);
        $n->send($m, $to);
    }
}
