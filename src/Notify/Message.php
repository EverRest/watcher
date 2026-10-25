<?php

declare(strict_types=1);

namespace Watcher\Notify;

/** Provider-neutral message; a notifier turns it into its own format. */
final class Message
{
    /** @param list<array{kind: string, text: string}> $sections */
    public function __construct(
        public readonly string $text,
        public readonly array $sections = [],
        public readonly ?DeliveryProgress $deliveryProgress = null,
    ) {
    }
}
