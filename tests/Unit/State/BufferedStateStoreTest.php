<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\State;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\State\BufferedStateStore;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class BufferedStateStoreTest extends TestCase
{
    private InMemoryStateStore $inner;
    private BufferedStateStore $buffer;

    protected function setUp(): void
    {
        $this->inner = new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00')));
        $this->buffer = new BufferedStateStore($this->inner);
    }

    public function testReadsSeeBufferedWritesButTheInnerStoreDoesNot(): void
    {
        $this->inner->put('a', ['v' => 1], 100);
        $this->buffer->put('a', ['v' => 2], 100);
        $this->buffer->put('b', ['v' => 3], 100);

        self::assertSame(['v' => 2], $this->buffer->get('a'));
        self::assertSame(['v' => 3], $this->buffer->get('b'));
        self::assertSame(['v' => 1], $this->inner->get('a'));
        self::assertNull($this->inner->get('b'));
    }

    public function testFlushWritesEverythingWithItsTtlOnce(): void
    {
        $this->buffer->put('a', ['v' => 1], 100);
        $this->buffer->put('b', ['v' => 2], 200);
        $this->buffer->flush();

        self::assertSame(['v' => 1], $this->inner->get('a'));
        self::assertSame(['a' => 100, 'b' => 200], $this->inner->ttls());

        $this->inner->release('a', 'x');
        $this->buffer->flush();
        self::assertSame(['a' => 100, 'b' => 200], $this->inner->ttls(), 'a second flush has nothing left to write');
    }

    public function testDiscardDropsTheBuffer(): void
    {
        $this->buffer->put('a', ['v' => 1], 100);
        $this->buffer->discard();
        $this->buffer->flush();

        self::assertNull($this->buffer->get('a'));
        self::assertNull($this->inner->get('a'));
    }

    public function testLocksAndCountersGoStraightThrough(): void
    {
        self::assertTrue($this->buffer->acquire('lock', 'me', 60));
        self::assertFalse($this->inner->acquire('lock', 'other', 60), 'lock is real-time');
        $this->buffer->release('lock', 'me');
        self::assertTrue($this->inner->acquire('lock', 'other', 60));

        self::assertEqualsWithDelta(1.5, $this->buffer->increment('n', 1.5, 60), 1e-9);
        self::assertEqualsWithDelta(3.0, $this->inner->increment('n', 1.5, 60), 1e-9);
    }
}
