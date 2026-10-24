<?php

declare(strict_types=1);

namespace Watcher\Reduce;

/** What the template miner had to give up (spec 004 FR-010); read by the digest. */
final class MiningStats
{
    /**
     * @param int  $distinctMessages stored distinct masked messages
     * @param int  $droppedMessages  events whose message did not fit under the cap (their groups show no text)
     * @param bool $budgetExhausted  the comparison budget ran out; some stored messages were not clustered (no text)
     */
    public function __construct(
        public readonly int $distinctMessages = 0,
        public readonly int $droppedMessages = 0,
        public readonly bool $budgetExhausted = false,
    ) {
    }

    public function truncated(): bool
    {
        return $this->droppedMessages > 0 || $this->budgetExhausted;
    }
}
