<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Psr\Clock\ClockInterface;
use Watcher\State\Redis\PredisClient;
use Watcher\State\RedisStateStore;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FakePredis;
use Watcher\Tests\Fakes\FakeRedisServer;

/** The whole StateStore contract through the Predis adapter and a Predis-shaped double. */
final class PredisStateStoreTest extends StateStoreContractTest
{
    protected function makeStore(ClockInterface $clock): StateStore
    {
        return new RedisStateStore(new PredisClient(new FakePredis(new FakeRedisServer($clock))), $clock);
    }
}
