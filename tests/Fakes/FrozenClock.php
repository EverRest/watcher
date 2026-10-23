<?php

declare(strict_types=1);

namespace Watcher\Tests\Fakes;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    /** @param string $modifier e.g. '+5 minutes' */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
