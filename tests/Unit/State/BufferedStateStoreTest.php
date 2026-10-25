<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\State;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Watcher\State\BufferedStateStore;
use Watcher\State\StateStore;
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

    public function testFlushKeepsUnwrittenKeysWhenTheInnerPutFails(): void
    {
        $inner = new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00')));
        $buffer = new BufferedStateStore(new class ($inner) implements StateStore {
            private bool $failB = true;

            public function __construct(private InMemoryStateStore $inner)
            {
            }

            public function get(string $key): ?array
            {
                return $this->inner->get($key);
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
                if ($key === 'b' && $this->failB) {
                    $this->failB = false;
                    throw new RuntimeException('put failed');
                }
                $this->inner->put($key, $value, $ttlSeconds);
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                return $this->inner->acquire($key, $owner, $ttlSeconds);
            }

            public function release(string $key, string $owner): void
            {
                $this->inner->release($key, $owner);
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                return $this->inner->increment($key, $by, $ttlSeconds);
            }
        });
        $buffer->put('a', ['v' => 1], 100);
        $buffer->put('b', ['v' => 2], 200);
        $buffer->put('c', ['v' => 3], 300);

        try {
            $buffer->flush();
            self::fail('expected RuntimeException');
        } catch (RuntimeException) {
        }

        self::assertSame(['v' => 1], $inner->get('a'));
        self::assertNull($inner->get('b'));
        self::assertSame(['v' => 2], $buffer->get('b'));
        self::assertSame(['v' => 3], $buffer->get('c'));

        $buffer->flush();
        self::assertSame(['v' => 2], $inner->get('b'));
        self::assertSame(['v' => 3], $inner->get('c'));
        self::assertSame(['a' => 100, 'b' => 200, 'c' => 300], $inner->ttls());
        $buffer->flush();
        self::assertSame(['a' => 100, 'b' => 200, 'c' => 300], $inner->ttls(), 'nothing left buffered after a successful retry');
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
