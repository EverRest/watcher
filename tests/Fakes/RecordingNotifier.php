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

    /** When set, sends fail from this 1-based call on; earlier ones are delivered. */
    public ?int $failFrom = null;

    /** The next this many sends fail, then sends work again. */
    public int $failFirst = 0;

    private int $calls = 0;

    public function send(Message $message, Destination $to): void
    {
        if ($this->failFirst > 0) {
            --$this->failFirst;

            throw new DeliveryFailed('send refused');
        }
        if ($this->fail || ($this->failFrom !== null && ++$this->calls >= $this->failFrom)) {
            throw new DeliveryFailed('send refused');
        }
        $this->sent[] = $message->text;
    }
}
