<?php

declare(strict_types=1);

namespace Watcher\Observe;

/** A sink that waits on the network: the dispatcher tells it how much of the run deadline is left (RunGuard). */
interface TimeBoundedSink
{
    public function within(int $seconds): void;
}
