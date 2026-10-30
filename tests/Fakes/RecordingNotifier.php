<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;

/** Keeps every message it was asked to send; can refuse all sends, to test the failure path. */
final class RecordingNotifier implements Notifier
{
    /** @var list<string> the plain text of each delivered message */
    public array $sent = [];

    public bool $fail = false;

    public function send(Message $message, Destination $to): void
    {
        if ($this->fail) {
            throw new DeliveryFailed('send refused');
        }
        $this->sent[] = $message->text;
    }
}
