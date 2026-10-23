<?php

declare(strict_types=1);

namespace Watcher\Notify;

use JsonSerializable;

/** Where to deliver. The webhook is a secret: it must never reach a log, dump or serialisation. */
final class Destination implements JsonSerializable
{
    // Held in a closure: var_export/(array) casts of an object expose properties, never closure captures.
    private readonly \Closure $reveal;

    public function __construct(string $webhook)
    {
        $this->reveal = static fn (): string => $webhook;
    }

    /** Only the notifier that performs the delivery should call this. */
    public function webhook(): string
    {
        return ($this->reveal)();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['webhook' => '[redacted]'];
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['webhook' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Destination is not serialisable.');
    }
}
