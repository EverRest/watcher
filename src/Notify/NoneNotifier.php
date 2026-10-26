<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** Dashboard-only mode (`notify.digest.driver: none`): accepts every message and drops it. */
final class NoneNotifier implements Notifier
{
    public function send(Message $message, Destination $to): void
    {
    }
}
