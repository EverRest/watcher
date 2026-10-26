<?php

declare(strict_types=1);

namespace Watcher\Observe;

/** A destination for one run's events. The events arrive already sanitized for this sink (EventSanitizer). */
interface EventSink
{
    /**
     * @param list<array<string, mixed>> $events in emission order
     *
     * @throws SinkFailed on any failure; its message never holds a path with credentials, a URL, a token or an exception text
     */
    public function emit(array $events): void;
}
