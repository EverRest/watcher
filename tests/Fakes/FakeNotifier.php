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
    public int $attempts = 0;
    private ?DeliveryFailed $failure = null;
    private bool $once = false;

    /** @return array{message: Message, to: Destination} the latest delivery; fails the test when nothing was sent */
    public function last(): array
    {
        return end($this->sent) ?: throw new \LogicException('nothing was sent');
    }

    /** Forgets what was sent so far (a method, so a static analyser does not assume the list stays empty after the next run). */
    public function forget(): void
    {
        $this->sent = [];
    }

    public function failWith(DeliveryFailed $e): void
    {
        $this->failure = $e;
    }

    /** Fails the next send only. */
    public function failOnce(DeliveryFailed $e): void
    {
        $this->failure = $e;
        $this->once = true;
    }

    public function send(Message $message, Destination $to): void
    {
        ++$this->attempts;
        if ($this->failure !== null) {
            $e = $this->failure;
            if ($this->once) {
                $this->failure = null;
                $this->once = false;
            }

            throw $e;
        }
        $this->sent[] = ['message' => $message, 'to' => $to];
    }
}
