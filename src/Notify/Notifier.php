<?php

declare(strict_types=1);

namespace Watcher\Notify;

interface Notifier
{
    /** @throws DeliveryFailed */
    public function send(Message $message, Destination $to): void;
}
