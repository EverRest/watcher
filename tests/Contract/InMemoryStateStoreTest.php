<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use Psr\Clock\ClockInterface;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class InMemoryStateStoreTest extends StateStoreContractTest
{
    protected function makeStore(ClockInterface $clock): StateStore
    {
        return new InMemoryStateStore($clock);
    }
}
