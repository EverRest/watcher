<?php

declare(strict_types=1);

namespace Watcher\Issues;

use DateTimeImmutable;
use Watcher\Level;

/** Exactly the allowlisted Sentry fields (research R7); there is no parameter for anything else, by design. */
final class IssueRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $culprit,
        public readonly Level $level,
        public readonly DateTimeImmutable $firstSeen,
        public readonly DateTimeImmutable $lastSeen,
        public readonly int $countInWindow,
        public readonly bool $isRegression = false,
    ) {
    }
}
