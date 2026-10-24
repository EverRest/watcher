<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Psr\Clock\ClockInterface;
use Watcher\State\RedisStateStore;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FakeRedisServer;

/** The whole StateStore contract against RedisStateStore over the recording in-memory server. */
final class FakeRedisStateStoreTest extends StateStoreContractTest
{
    protected function makeStore(ClockInterface $clock): StateStore
    {
        return new RedisStateStore(new FakeRedisServer($clock), $clock);
    }
}
