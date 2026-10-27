<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\ReadOnlyStateStore;
use Watcher\Mcp\ReadOnlyViolation;
use Watcher\State\Purgeable;
use Watcher\State\RenewableLock;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class ReadOnlyStateStoreTest extends TestCase
{
    public function testGetPassesThrough(): void
    {
        $inner = new InMemoryStateStore(new FrozenClock(new \DateTimeImmutable('2026-10-25T00:00:00Z')));
        $inner->put('k', ['v' => 1], 60);

        self::assertSame(['v' => 1], (new ReadOnlyStateStore($inner))->get('k'));
    }

    public function testEveryMutatorThrowsWithoutKeyOrValueInTheMessage(): void
    {
        $s = new ReadOnlyStateStore(new InMemoryStateStore(new FrozenClock(new \DateTimeImmutable('2026-10-25T00:00:00Z'))));
        foreach ([
            fn () => $s->put('SECRETKEY', ['x' => 'SECRETVALUE'], 1),
            fn () => $s->increment('SECRETKEY', 1.0, 1),
            fn () => $s->acquire('SECRETKEY', 'SECRETOWNER', 1),
            fn () => $s->release('SECRETKEY', 'SECRETOWNER'),
        ] as $write) {
            try {
                $write();
                self::fail('a write was accepted');
            } catch (ReadOnlyViolation $e) {
                self::assertStringNotContainsString('SECRET', $e->getMessage());
            }
        }
        $implemented = class_implements($s);
        foreach ([Purgeable::class, RenewableLock::class] as $capability) {
            self::assertArrayNotHasKey($capability, $implemented);
        }
    }
}
