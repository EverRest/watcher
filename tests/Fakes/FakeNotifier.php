<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;

final class FakeNotifier implements Notifier
{
    /** @var list<array{message: Message, to: Destination}> */
    public array $sent = [];
    private ?DeliveryFailed $failure = null;

    public function failWith(DeliveryFailed $e): void
    {
        $this->failure = $e;
    }

    public function send(Message $message, Destination $to): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->sent[] = ['message' => $message, 'to' => $to];
    }
}
