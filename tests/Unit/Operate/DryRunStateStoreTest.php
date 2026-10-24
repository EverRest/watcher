<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use PHPUnit\Framework\TestCase;
use Watcher\Operate\DryRunStateStore;
use Watcher\State\StateStore;

final class DryRunStateStoreTest extends TestCase
{
    /** A real store that serves fixed reads and fails the test on any write. */
    private function spy(): StateStore
    {
        return new class ($this) implements StateStore {
            public function __construct(private readonly TestCase $t)
            {
            }

            public function get(string $key): ?array
            {
                return $key === 'verdict:svc:a' ? ['v' => 'noise'] : (str_starts_with($key, 'run:') ? ['done' => true] : null);
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
                $this->t->fail('put reached the real store');
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                $this->t->fail('acquire reached the real store');
            }

            public function release(string $key, string $owner): void
            {
                $this->t->fail('release reached the real store');
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                $this->t->fail('increment reached the real store');
            }
        };
    }

    public function testReadsPassThrough(): void
    {
        self::assertSame(['v' => 'noise'], (new DryRunStateStore($this->spy()))->get('verdict:svc:a'));
        self::assertNull((new DryRunStateStore($this->spy()))->get('verdict:svc:other'));
    }

    public function testRunRecordOfRealStoreIsIgnored(): void
    {
        self::assertNull((new DryRunStateStore($this->spy()))->get('run:svc:2026-01-01'));
    }

    public function testWritesAreSwallowedAndSucceed(): void
    {
        $s = new DryRunStateStore($this->spy());
        $s->put('k', ['a' => 1], 60);
        self::assertTrue($s->acquire('lock:x', 'me', 60));
        $s->release('lock:x', 'me');
        self::assertSame(2.5, $s->increment('spend:x', 2.5, 60));
        self::addToAssertionCount(1);
    }

    public function testShadowMakesTheRunSelfConsistent(): void
    {
        $s = new DryRunStateStore($this->spy());
        $s->put('verdict:svc:a', ['v' => 'interesting'], 60);
        $s->put('run:svc:2026-01-01', ['done' => 1], 60);
        self::assertSame(['v' => 'interesting'], $s->get('verdict:svc:a'));
        self::assertSame(['done' => 1], $s->get('run:svc:2026-01-01'));
    }

    public function testShadowDoesNotLeakToAnotherInstance(): void
    {
        $real = $this->spy();
        (new DryRunStateStore($real))->put('verdict:svc:a', ['v' => 'x'], 60);
        self::assertSame(['v' => 'noise'], (new DryRunStateStore($real))->get('verdict:svc:a'));
    }

    public function testSpendIsCumulativeInMemoryAndNeverReachesTheRealStore(): void
    {
        $s = new DryRunStateStore($this->spy()); // the spy fails the test if increment reaches it
        self::assertSame(1.0, $s->increment('spend:usd', 1.0, 60));
        self::assertSame(1.5, $s->increment('spend:usd', 0.5, 60));
        self::assertSame(['total' => 1.5], $s->get('spend:usd'));
        self::assertNull((new DryRunStateStore($this->spy()))->get('spend:usd'));
    }
}
