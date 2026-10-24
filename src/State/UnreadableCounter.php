<?php

declare(strict_types=1);

namespace Watcher\State;

/** A store that treats an undecodable entry as missing and counts it; surfaced as `state_unreadable` in the run summary. */
interface UnreadableCounter
{
    public function unreadableEntries(): int;
}
