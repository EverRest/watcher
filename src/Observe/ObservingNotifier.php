<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Closure;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;

/** Records a failed chat delivery for the run's events (channel and status code only) and rethrows it untouched. */
final class ObservingNotifier implements Notifier
{
    /** 'digest' while the digest is being sent; every other message is "other". */
    private string $kind = 'other';

    /** @var Closure(): RunObservation */
    private Closure $observation;

    public function __construct(private readonly Notifier $inner)
    {
        $this->observation = static fn (): RunObservation => new RunObservation();
    }

    /** @param Closure(): RunObservation $observation the current run's observation (the ledger exists only after this notifier) */
    public function observeWith(Closure $observation): void
    {
        $this->observation = $observation;
    }

    public function kind(string $kind): void
    {
        $this->kind = $kind;
    }

    public function send(Message $message, Destination $to): void
    {
        try {
            $this->inner->send($message, $to);
        } catch (DeliveryFailed $e) {
            ($this->observation)()->deliveryFailed($to->driver(), $this->kind, $e);

            throw $e;
        }
    }
}
