<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Watcher\Observe\EventSink;
use Watcher\Observe\TimeBoundedSink;

final class TimeBoundedEventSink implements EventSink, TimeBoundedSink
{
    public ?int $within = null;
    public int $emitted = 0;

    public function within(int $seconds): void
    {
        $this->within = $seconds;
    }

    public function emit(array $events): void
    {
        ++$this->emitted;
    }
}
