<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Throwable;
use Watcher\Observe\EventSink;

final class RecordingEventSink implements EventSink
{
    /** @var list<list<array<string, mixed>>> */
    public array $received = [];

    /** @param (callable(): void)|null $onEmit */
    public function __construct(private readonly ?Throwable $throws = null, private readonly mixed $onEmit = null)
    {
    }

    public function emit(array $events): void
    {
        if ($this->onEmit !== null) {
            ($this->onEmit)();
        }
        if ($this->throws !== null) {
            throw $this->throws;
        }
        $this->received[] = $events;
    }
}
