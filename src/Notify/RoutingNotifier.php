<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** Hands each message to the notifier of the destination's driver. Config validation guarantees a known driver. */
final class RoutingNotifier implements Notifier
{
    public function __construct(
        private readonly Notifier $slack,
        private readonly Notifier $telegram,
        private readonly Notifier $discord,
    ) {
    }

    public function send(Message $message, Destination $to): void
    {
        $inner = match ($to->driver()) {
            'telegram' => $this->telegram,
            'discord' => $this->discord,
            default => $this->slack,
        };
        $inner->send($message, $to);
    }
}
