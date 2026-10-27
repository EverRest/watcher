<?php

declare(strict_types=1);

namespace Watcher\Fleet;

use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;

/**
 * Combined-digest mode: notices (baseline, failure) go out at once; a digest (it opens with a header section,
 * the same rule the dry-run notifier uses) is held for CombinedDigest.
 */
final class CollectingNotifier implements Notifier
{
    /** @var list<Message> */
    private array $held = [];

    public function __construct(private readonly Notifier $inner)
    {
    }

    public function send(Message $message, Destination $to): void
    {
        if (in_array('header', array_column($message->sections, 'kind'), true)) {
            $this->held[] = $message;

            return;
        }
        $this->inner->send($message, $to);
    }

    /** @return list<Message> */
    public function held(): array
    {
        return $this->held;
    }
}
