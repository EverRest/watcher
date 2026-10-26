<?php

declare(strict_types=1);

namespace Watcher\Notify;

interface Notifier
{
    /** Most a chat or webhook reply may cost to read, counted as it streams in (ResponseLimit). Three APIs need a few hundred bytes. */
    public const MAX_RESPONSE_BYTES = 64 * 1024;

    /** @throws DeliveryFailed */
    public function send(Message $message, Destination $to): void;
}
